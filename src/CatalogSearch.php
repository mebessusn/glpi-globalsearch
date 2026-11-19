<?php

namespace GlpiPlugin\Omnisearch;

use Dropdown;
use Session;

/**
 * Search for "catalog" items: assets (computers, network devices, printers,
 * phones, monitors, peripherals), users, software, documents and projects.
 *
 * Every word must match somewhere on the item: in one of its text fields,
 * or (assets) an IP / MAC address, or (users) an email address.
 * Visibility follows GLPI: the class must be viewable, entity restrictions
 * are applied in SQL, and types with finer rules (users, documents, projects)
 * also go through canViewItem() for each item.
 *
 * Used by Searcher (needs its filters, terms and helper methods).
 */
trait CatalogSearch
{
    /**
     * type key => config
     *  classes: GLPI itemtypes searched (merged in one tab)
     *  fields:  column => match label ("title" = the item's name)
     *  extra:   'ip' / 'mac' (network addresses of the item), 'email' (user emails)
     *  person:  columns that tie the item to a person (for the "Touched by" filter)
     *  check:   'entity' = full Read right + entity restriction is enough. On GLPI 11+
     *                      assets/software also have "read assigned" / "read owned":
     *                      GLPI's own visibility criteria go into the query and,
     *                      without full Read, every item also goes through canViewItem();
     *           'item'   = always canViewItem() per item (finer visibility rules)
     */
    private static function catalogConfig(): array
    {
        $asset_fields = [
            'name'        => 'title',
            'serial'      => 'serial',
            'otherserial' => 'inventory',
            'contact'     => 'contact',
            'contact_num' => 'contact',
            'comment'     => 'comment',
        ];
        return [
            'asset' => [
                'classes' => ['Computer', 'NetworkEquipment', 'Printer', 'Phone', 'Monitor', 'Peripheral'],
                'fields'  => $asset_fields,
                'extra'   => ['ip', 'mac'],
                'person'  => ['users_id', 'users_id_tech'],
                'check'   => 'entity',
            ],
            'user' => [
                'classes' => ['User'],
                'fields'  => [
                    'name'                => 'login',
                    'firstname'           => 'title',
                    'realname'            => 'title',
                    'phone'               => 'phone',
                    'phone2'              => 'phone',
                    'mobile'              => 'phone',
                    'registration_number' => 'employee',
                ],
                'extra'   => ['email'],
                'person'  => [],
                'check'   => 'item',
            ],
            'software' => [
                'classes' => ['Software'],
                'fields'  => ['name' => 'title', 'comment' => 'comment'],
                'extra'   => [],
                'person'  => ['users_id', 'users_id_tech'],
                'check'   => 'entity',
            ],
            'document' => [
                'classes' => ['Document'],
                'fields'  => ['name' => 'title', 'filename' => 'filename', 'comment' => 'comment'],
                'extra'   => [],
                'person'  => ['users_id'],
                'check'   => 'item',
            ],
            'project' => [
                'classes' => ['Project'],
                'fields'  => ['name' => 'title', 'code' => 'code', 'content' => 'description', 'comment' => 'comment'],
                'extra'   => [],
                'person'  => ['users_id'],
                'check'   => 'item',
            ],
        ];
    }

    public static function isCatalogType(string $type): bool
    {
        return isset(self::catalogConfig()[$type]);
    }

    /** Classes of a catalog type that this GLPI has and this user may view. */
    private static function catalogClasses(string $type): array
    {
        return array_values(array_filter(
            self::catalogConfig()[$type]['classes'],
            static fn($c) => class_exists($c) && $c::canView()
        ));
    }

    private static function catalogLabel(string $type): string
    {
        return match ($type) {
            'asset'  => __('Assets'),
            default  => self::catalogConfig()[$type]['classes'][0]::getTypeName(Session::getPluralNumber()),
        };
    }

    /** Fields searched for the current "Found in" filter, or null when the filter excludes this type. */
    private function catalogFields(array $cfg): ?array
    {
        return match ($this->filters['in']) {
            'any'         => $cfg['fields'],
            'title'       => array_filter($cfg['fields'], static fn($l) => in_array($l, ['title', 'login'], true)),
            'description' => array_filter($cfg['fields'], static fn($l) => in_array($l, ['comment', 'description'], true)),
            default       => null, // followups / tasks: not on these items
        };
    }

    /**
     * Visible, filtered, sorted keys ("Class:id") for a catalog type.
     */
    private function resolveCatalog(string $type): array
    {
        global $DB;

        $empty = ['ids' => [], 'matched' => [], 'capped' => false];
        $cfg   = self::catalogConfig()[$type];
        $fields = $this->catalogFields($cfg);
        if (
            $fields === null
            || $this->filters['status'] !== 'any'                      // ticket status: not on these items
            || ($this->filters['person'] > 0 && $cfg['person'] === []) // users tab has no "touched by"
        ) {
            return $empty;
        }

        $rows    = [];   // [class, id, date_mod, date_creation]
        $matched = [];   // key => [label => true]
        $capped  = false;

        foreach (self::catalogClasses($type) as $class) {
            $table = $class::getTable();
            /** @var array<int, array<string,bool>>|null $hits id => labels; null = no word filter */
            $hits = null;

            if ($this->terms !== []) {
                foreach ($this->terms as $term) {
                    $termHits = $this->catalogTermHits($class, $table, $cfg, $fields, $term);
                    if ($hits === null) {
                        $hits = $termHits;
                    } else {
                        $hits = array_intersect_key($hits, $termHits);
                        foreach ($hits as $id => $labels) {
                            $hits[$id] = $labels + $termHits[$id];
                        }
                    }
                    if ($hits === []) {
                        break;
                    }
                }
                if ($hits === []) {
                    continue;
                }
            }

            $where = ['is_deleted' => 0];
            if ($DB->fieldExists($table, 'is_template')) {
                $where['is_template'] = 0;
            }
            if ($hits !== null) {
                $where['id'] = array_keys($hits);
            }
            if ($this->filters['person'] > 0) {
                $or = [];
                foreach ($cfg['person'] as $col) {
                    $or[$col] = $this->filters['person'];
                }
                $personOr = $or;
                if ($class === 'Project') {
                    // project team members
                    $team = [];
                    foreach ($DB->request([
                        'SELECT' => ['projects_id'],
                        'FROM'   => 'glpi_projectteams',
                        'WHERE'  => ['itemtype' => 'User', 'items_id' => $this->filters['person']],
                    ]) as $r) {
                        $team[] = (int) $r['projects_id'];
                    }
                    if ($team !== []) {
                        $personOr[] = ['id' => $team];
                    }
                }
                $where[] = ['OR' => $personOr];
            }
            $where = array_merge($where, $this->dateCriteria());
            // GLPI 11+: "read assigned" / "read owned" profiles may only see their own
            // assets/software. Apply GLPI's own visibility criteria in SQL...
            $assignable = interface_exists('Glpi\\Features\\AssignableItemInterface')
                && is_a($class, 'Glpi\\Features\\AssignableItemInterface', true);
            if ($assignable) {
                foreach ($class::getAssignableVisiblityCriteria($table) as $criterion) {
                    $where[] = $criterion;
                }
            }
            // ...and check each item with canViewItem() unless the user has full Read.
            $perItem = $cfg['check'] === 'item'
                || ($assignable && !Session::haveRight($class::$rightname, READ));
            if ($class !== 'User' && function_exists('getEntitiesRestrictCriteria') && $DB->fieldExists($table, 'entities_id')) {
                $entities = getEntitiesRestrictCriteria($table, '', '', $DB->fieldExists($table, 'is_recursive'));
                if ($entities !== []) {
                    $where[] = $entities;
                }
            }

            $fetched = 0;
            foreach ($DB->request([
                'SELECT' => ['id', 'date_mod', 'date_creation'],
                'FROM'   => $table,
                'WHERE'  => $where,
                'LIMIT'  => self::MAX_MATCHES + 1,
            ]) as $row) {
                if (++$fetched > self::MAX_MATCHES) {
                    $capped = true; // more candidates than read
                    break;
                }
                $id = (int) $row['id'];
                if ($perItem) {
                    $ok = $this->canSee($class, $id);
                    if ($ok === null) {
                        $capped = true;
                        break 2;
                    }
                    if (!$ok) {
                        continue;
                    }
                }
                $key = $class . ':' . $id;
                $rows[] = [$key, (string) $row['date_mod'], (string) $row['date_creation']];
                $labels = $hits[$id] ?? [];
                if ($this->filters['person'] > 0) {
                    $labels['person'] = true;
                }
                $matched[$key] = $labels;
            }
        }

        // merge classes in the chosen order
        $sort = $this->filters['sort'];
        $col  = str_starts_with($sort, 'opened') ? 2 : 1;
        $desc = str_ends_with($sort, 'desc');
        usort($rows, static function ($a, $b) use ($col, $desc) {
            $c = strcmp($a[$col], $b[$col]) ?: strcmp($a[0], $b[0]);
            return $desc ? -$c : $c;
        });
        if (count($rows) > self::MAX_MATCHES) {
            $rows   = array_slice($rows, 0, self::MAX_MATCHES);
            $capped = true;
        }

        return ['ids' => array_column($rows, 0), 'matched' => $matched, 'capped' => $capped];
    }

    /**
     * IDs of $class matching one word, with where it matched.
     *
     * @return array<int, array<string,bool>>
     */
    private function catalogTermHits(string $class, string $table, array $cfg, array $fields, string $term): array
    {
        global $DB;

        $hits = [];
        $columns = array_keys($fields);
        if ($columns !== []) {
            foreach ($DB->request([
                'SELECT' => array_merge(['id'], $columns),
                'FROM'   => $table,
                'WHERE'  => [$this->likeTerm($columns, $term)],
                'LIMIT'  => self::MAX_MATCHES * 2,
            ]) as $row) {
                foreach ($fields as $column => $label) {
                    if (mb_stripos(self::stored((string) $row[$column]), $term) !== false) {
                        $hits[(int) $row['id']][$label] = true;
                    }
                }
            }
        }
        if ($this->filters['in'] !== 'any') {
            return $hits;
        }

        if (in_array('ip', $cfg['extra'], true)) {
            foreach ($DB->request([
                'SELECT' => ['mainitems_id'],
                'FROM'   => 'glpi_ipaddresses',
                'WHERE'  => ['mainitemtype' => $class, 'is_deleted' => 0, $this->likeTerm(['name'], $term)],
                'LIMIT'  => self::MAX_MATCHES * 2,
            ]) as $row) {
                $hits[(int) $row['mainitems_id']]['ip'] = true;
            }
        }
        if (in_array('mac', $cfg['extra'], true)) {
            // accept aa-bb-cc / AA:BB:CC (GLPI stores lowercase with colons)
            $mac = strtolower(str_replace('-', ':', $term));
            foreach ($DB->request([
                'SELECT' => ['items_id'],
                'FROM'   => 'glpi_networkports',
                'WHERE'  => ['itemtype' => $class, 'is_deleted' => 0, $this->likeTerm(['mac'], $mac)],
                'LIMIT'  => self::MAX_MATCHES * 2,
            ]) as $row) {
                $hits[(int) $row['items_id']]['mac'] = true;
            }
        }
        if (in_array('email', $cfg['extra'], true)) {
            foreach ($DB->request([
                'SELECT' => ['users_id'],
                'FROM'   => 'glpi_useremails',
                'WHERE'  => [$this->likeTerm(['email'], $term)],
                'LIMIT'  => self::MAX_MATCHES * 2,
            ]) as $row) {
                $hits[(int) $row['users_id']]['email'] = true;
            }
        }
        return $hits;
    }

    /**
     * Result row for "Class:id".
     */
    private function buildCatalogResult(string $key, array $matched): array
    {
        global $DB;

        [$class, $id] = explode(':', $key, 2);
        $id   = (int) $id;
        $item = new $class();
        $item->getFromDB($id);
        $f = $item->fields;
        $s = static fn($k) => self::stored((string) ($f[$k] ?? ''));

        $details = [];
        $snippetSource = '';
        $badges = [];

        if ($class === 'User') {
            $full  = trim($s('firstname') . ' ' . $s('realname'));
            $title = $full !== '' ? sprintf('%s (%s)', $full, $s('name')) : $s('name');
            $emails = [];
            foreach ($DB->request(['SELECT' => ['email'], 'FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => $id], 'ORDER' => 'is_default DESC', 'LIMIT' => 3]) as $r) {
                $emails[] = self::stored((string) $r['email']);
            }
            $details[__('Email')] = implode(', ', $emails);
            $details[__('Phone')] = implode(' / ', array_filter([$s('phone'), $s('mobile')]));
            $details[__('Administrative number')] = $s('registration_number');
            $details[__('Location')] = $this->dropdownName('glpi_locations', $f['locations_id'] ?? 0);
            if (empty($f['is_active'])) {
                $badges[] = __('Inactive');
            }
        } else {
            $title = $s('name') !== '' ? $s('name') : sprintf('#%d', $id);
            if (isset($f['serial'])) {
                $details[__('Serial number')] = $s('serial');
                $details[__('Inventory number')] = $s('otherserial');
            }
            if (isset($f['states_id'])) {
                $details[__('Status')] = $this->dropdownName('glpi_states', $f['states_id']);
            }
            if ($class === 'Project') {
                $details[__('Code')] = $s('code');
                $details[__('Status')] = $this->dropdownName('glpi_projectstates', $f['projectstates_id'] ?? 0);
                $details[__('Percent done')] = isset($f['percent_done']) ? (int) $f['percent_done'] . '%' : '';
                $snippetSource = $s('content');
            }
            if ($class === 'Document') {
                $details[__('File')] = $s('filename');
            }
            if ($class === 'Software') {
                $details[__('Publisher')] = $this->dropdownName('glpi_manufacturers', $f['manufacturers_id'] ?? 0);
            }
            if (isset($f['locations_id'])) {
                $details[__('Location')] = $this->dropdownName('glpi_locations', $f['locations_id']);
            }
            if (!empty($f['users_id']) && $class !== 'Document') {
                $details[$class === 'Project' ? __('Manager') : __('User')] = self::personLabel((int) $f['users_id']);
            }
            if (in_array($class, self::catalogConfig()['asset']['classes'], true)) {
                $ips = [];
                foreach ($DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_ipaddresses', 'WHERE' => ['mainitemtype' => $class, 'mainitems_id' => $id, 'is_deleted' => 0, 'NOT' => ['name' => '']], 'LIMIT' => 4]) as $r) {
                    $ips[] = (string) $r['name'];
                }
                $details[__('IP')] = implode(', ', $ips);
            }
            if ($snippetSource === '' || !$this->matchesAny(strip_tags($snippetSource))) {
                $snippetSource = $s('comment') !== '' ? $s('comment') : $snippetSource;
            }
        }

        return [
            'title'    => $title,
            'url'      => $item::getFormURLWithID($id),
            'matched'  => $matched,
            'snippet'  => self::snippet($snippetSource, $this->terms),
            'date'     => (string) ($f['date_mod'] ?? ''),
            'opened'   => '',
            'status'   => null,
            'itemtype' => $class,
            'typename' => $class::getTypeName(1),
            'icon'     => method_exists($class, 'getIcon') ? (string) $class::getIcon() : '',
            'details'  => array_filter($details, static fn($v) => $v !== '' && $v !== null && $v !== '&nbsp;'),
            'badges'   => $badges,
        ];
    }

    private function dropdownName(string $table, $id): string
    {
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }
        $name = Dropdown::getDropdownName($table, $id);
        return is_string($name) ? self::stored(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }
}
