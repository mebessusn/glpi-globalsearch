<?php

namespace GlpiPlugin\Globalsearch;

use Change;
use ChangeTask;
use CommonDBTM;
use ITILFollowup;
use ITILSolution;
use KnowbaseItem;
use Problem;
use ProblemTask;
use Session;
use Ticket;
use TicketTask;
use User;

/**
 * Full-text search across tickets, problems, changes (title, description,
 * followups, tasks) and the knowledge base, with filters, a "person" filter
 * (everything a user touched) and pagination.
 *
 * Strategy:
 *  1. Collect matching parent IDs per type with LIKE queries (no paging yet).
 *     Private followups/tasks the user may not see are excluded in SQL.
 *  2. Load the candidate parents once (id, status, dates) with the filters
 *     and the user's entity restriction applied in SQL, in sort order.
 *  3. Keep only items GLPI lets the user see: users with "see all" on the type
 *     only need the entity check; everyone else goes through canViewItem().
 *  4. Paginate that list and build titles/snippets for the visible page only.
 */
class Searcher
{
    /** Hard ceiling of matching items considered per type (keeps huge result sets fast). */
    public const MAX_MATCHES = 5000;

    public const PER_PAGE_OPTIONS = [25, 50, 100];

    /** Rights bits (same values on GLPI 10, 11 and 12). */
    private const RIGHT_READALL    = 1024;
    private const RIGHT_SEEPUBLIC  = 1;
    private const RIGHT_SEEPRIVATE = 8192;

    /** type key => [itemtype, task class, task FK, actor (link) class] */
    private const ITIL = [
        'ticket'  => [Ticket::class, TicketTask::class, 'tickets_id', 'Ticket_User'],
        'problem' => [Problem::class, ProblemTask::class, 'problems_id', 'Problem_User'],
        'change'  => [Change::class, ChangeTask::class, 'changes_id', 'Change_User'],
    ];

    public const TYPES = ['ticket', 'problem', 'change', 'kb'];

    /** @var string[] */
    private array $terms;

    private array $filters;

    /** @var array<string, array{ids:int[], matched:array<int,array<string,bool>>, capped:bool}> */
    private array $cache = [];

    /* ------------------------------------------------------------------ */
    /* GLPI 10 compatibility                                               */
    /* ------------------------------------------------------------------ */

    /** GLPI 10 stores and receives text HTML-encoded and expects SQL-escaped values. */
    public static function isLegacy(): bool
    {
        return version_compare(GLPI_VERSION, '11.0.0', '<');
    }

    /** Request value as typed by the user (undoes GLPI 10's input sanitizing). */
    public static function rawInput(string $value): string
    {
        if (self::isLegacy() && class_exists('Glpi\\Toolbox\\Sanitizer')) {
            $value = \Glpi\Toolbox\Sanitizer::unsanitize($value);
        }
        return $value;
    }

    /** Stored DB text as plain text (GLPI 10 stores it HTML-encoded). */
    private static function stored(string $value): string
    {
        if (self::isLegacy() && class_exists('Glpi\\Toolbox\\Sanitizer')) {
            $value = \Glpi\Toolbox\Sanitizer::decodeHtmlSpecialChars($value);
        }
        return $value;
    }

    /* ------------------------------------------------------------------ */
    /* Input                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Read and validate filters from a request array ($_GET).
     * Every value is checked against a fixed list or cast, so nothing raw reaches SQL.
     */
    public static function filtersFromRequest(array $req): array
    {
        $pick = static fn(string $k, array $allowed, string $default): string
            => in_array((string) ($req[$k] ?? ''), $allowed, true) ? (string) $req[$k] : $default;
        $date = static function (string $k) use ($req): string {
            $v = (string) ($req[$k] ?? '');
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
        };
        $per_page = (int) ($req['per_page'] ?? 25);

        return [
            'type'     => $pick('type', self::TYPES, ''),
            'status'   => $pick('status', ['any', 'open', 'waiting', 'solved', 'closed', 'done'], 'any'),
            'period'   => $pick('period', ['any', '7', '30', '90', '365', 'custom'], 'any'),
            'from'     => $date('from'),
            'to'       => $date('to'),
            'in'       => $pick('in', ['any', 'title', 'description', 'followup', 'task'], 'any'),
            'sort'     => $pick('sort', ['updated_desc', 'updated_asc', 'opened_desc', 'opened_asc'], 'updated_desc'),
            'person'   => max(0, (int) ($req['person'] ?? 0)),
            'page'     => max(1, (int) ($req['page'] ?? 1)),
            'per_page' => in_array($per_page, self::PER_PAGE_OPTIONS, true) ? $per_page : 25,
        ];
    }

    public function __construct(string $query, array $filters)
    {
        $this->terms   = self::terms($query);
        $this->filters = $filters;
    }

    public function setPage(int $page): void
    {
        $this->filters['page'] = max(1, $page);
    }

    /** @return string[] */
    public function getTerms(): array
    {
        return $this->terms;
    }

    /** True when there is something to search for (words and/or a person). */
    public function hasCriteria(): bool
    {
        return $this->terms !== [] || $this->filters['person'] > 0;
    }

    /**
     * Split the query into up to 6 terms of 2+ characters.
     * Hostnames like web-01.corp.local stay intact (split on whitespace only).
     *
     * @return string[]
     */
    public static function terms(string $query): array
    {
        $parts = preg_split('/\s+/u', trim($query)) ?: [];
        $parts = array_filter($parts, static fn($p) => mb_strlen($p) >= 2);
        return array_slice(array_values(array_unique($parts)), 0, 6);
    }

    /* ------------------------------------------------------------------ */
    /* Public API                                                          */
    /* ------------------------------------------------------------------ */

    /** Types this user may search, in display order. */
    public function availableTypes(): array
    {
        $types = [];
        foreach (self::ITIL as $key => [$itemtype]) {
            if ($itemtype::canView()) {
                $types[] = $key;
            }
        }
        if (KnowbaseItem::canView()) {
            $types[] = 'kb';
        }
        return $types;
    }

    public static function typeLabel(string $type): string
    {
        return $type === 'kb'
            ? KnowbaseItem::getTypeName(1)
            : self::ITIL[$type][0]::getTypeName(Session::getPluralNumber());
    }

    /** Number of visible results for a type. */
    public function count(string $type): int
    {
        return count($this->resolve($type)['ids']);
    }

    /** Whether the type hit MAX_MATCHES (results beyond it are not shown). */
    public function isCapped(string $type): bool
    {
        return $this->resolve($type)['capped'];
    }

    /**
     * One page of results for a type.
     *
     * @return array<int, array{title:string,url:string,matched:string[],snippet:string,date:string,status:?int,itemtype:string}>
     */
    public function page(string $type): array
    {
        $res    = $this->resolve($type);
        $offset = ($this->filters['page'] - 1) * $this->filters['per_page'];
        $ids    = array_slice($res['ids'], $offset, $this->filters['per_page']);

        $out = [];
        foreach ($ids as $id) {
            $out[] = $this->buildResult($type, $id, array_keys($res['matched'][$id] ?? []));
        }
        return $out;
    }

    /**
     * Users whose login / first name / last name match the query words,
     * so the page can offer "everything this person touched".
     *
     * @return array<int, array{id:int,label:string}>
     */
    public function people(int $limit = 6): array
    {
        global $DB;

        if ($this->terms === [] || !User::canView()) {
            return [];
        }
        $out = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => User::getTable(),
            'WHERE'  => array_merge(['is_deleted' => 0, 'is_active' => 1], $this->likeAll(['name', 'firstname', 'realname'])),
            'ORDER'  => ['realname', 'firstname', 'name'],
            'LIMIT'  => $limit,
        ]);
        foreach ($iterator as $row) {
            $out[] = ['id' => (int) $row['id'], 'label' => self::userLabel($row)];
        }
        return $out;
    }

    public static function personLabel(int $users_id): string
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['id', 'name', 'firstname', 'realname'],
            'FROM'   => User::getTable(),
            'WHERE'  => ['id' => $users_id],
        ])->current();
        return $row ? self::userLabel($row) : sprintf('#%d', $users_id);
    }

    private static function userLabel(array $row): string
    {
        $full = trim(self::stored((string) $row['firstname']) . ' ' . self::stored((string) $row['realname']));
        $login = self::stored((string) $row['name']);
        return $full !== '' ? sprintf('%s (%s)', $full, $login) : $login;
    }

    /* ------------------------------------------------------------------ */
    /* Matching                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Visible, filtered, sorted IDs for a type (cached per request).
     *
     * @return array{ids:int[], matched:array<int,array<string,bool>>, capped:bool}
     */
    private function resolve(string $type): array
    {
        if (isset($this->cache[$type])) {
            return $this->cache[$type];
        }
        $empty = ['ids' => [], 'matched' => [], 'capped' => false];
        if (!$this->hasCriteria() || !in_array($type, $this->availableTypes(), true)) {
            return $this->cache[$type] = $empty;
        }
        $result = $type === 'kb' ? $this->resolveKb() : $this->resolveItil($type);
        return $this->cache[$type] = $result;
    }

    private function resolveItil(string $type): array
    {
        global $DB;

        [$itemtype, $taskclass, $fk, $actorclass] = self::ITIL[$type];
        $table  = $itemtype::getTable();
        $in     = $this->filters['in'];
        $capped = false;

        /** @var array<int, array<string,bool>> $matched id => where it matched */
        $matched = [];
        $add = function (iterable $rows, string $col, string $where) use (&$matched) {
            foreach ($rows as $row) {
                $matched[(int) $row[$col]][$where] = true;
            }
        };

        if ($this->terms !== []) {
            if (in_array($in, ['any', 'title', 'description'], true)) {
                $fields = $in === 'title' ? ['name'] : ($in === 'description' ? ['content'] : ['name', 'content']);
                foreach ($DB->request([
                    'SELECT' => ['id', 'name'],
                    'FROM'   => $table,
                    'WHERE'  => array_merge(['is_deleted' => 0], $this->likeAll($fields)),
                    'LIMIT'  => self::MAX_MATCHES + 1,
                ]) as $row) {
                    $where = $in !== 'any' ? $in : ($this->matchesAny(self::stored((string) $row['name'])) ? 'title' : 'description');
                    $matched[(int) $row['id']][$where] = true;
                }
            }
            if (in_array($in, ['any', 'followup'], true)) {
                $add($DB->request([
                    'SELECT' => ['items_id'],
                    'FROM'   => ITILFollowup::getTable(),
                    'WHERE'  => array_merge(
                        ['itemtype' => $itemtype],
                        $this->likeAll(['content']),
                        $this->privacyCriteria('followup', false)
                    ),
                    'LIMIT'  => self::MAX_MATCHES * 4,
                ]), 'items_id', 'followup');
            }
            if (in_array($in, ['any', 'task'], true)) {
                $add($DB->request([
                    'SELECT' => [$fk],
                    'FROM'   => $taskclass::getTable(),
                    'WHERE'  => array_merge($this->likeAll(['content']), $this->privacyCriteria('task', true)),
                    'LIMIT'  => self::MAX_MATCHES * 4,
                ]), $fk, 'task');
            }
        }

        $person = $this->filters['person'];
        if ($person > 0) {
            $touched = $this->touchedBy($person, $itemtype, $taskclass, $fk, $actorclass);
            if ($this->terms === []) {
                $matched = [];
                foreach ($touched as $id) {
                    $matched[$id]['person'] = true;
                }
            } else {
                $matched = array_intersect_key($matched, array_flip($touched));
                foreach ($matched as $id => $_) {
                    $matched[$id]['person'] = true;
                }
            }
        }

        if ($matched === []) {
            return ['ids' => [], 'matched' => [], 'capped' => false];
        }

        // Load candidates with filters, entity restriction and sort applied in SQL.
        $ids = array_keys($matched);
        $where = array_merge(['id' => $ids, 'is_deleted' => 0], $this->statusCriteria($itemtype), $this->dateCriteria());
        if (function_exists('getEntitiesRestrictCriteria')) {
            // empty when the user sees every entity (GLPI 10 returns [] then)
            $entities = getEntitiesRestrictCriteria($table);
            if ($entities !== []) {
                $where[] = $entities;
            }
        }
        $rows = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => $table,
            'WHERE'  => $where,
            'ORDER'  => $this->sortOrder('date'),
            'LIMIT'  => self::MAX_MATCHES + 1,
        ]);

        $seeAll  = Session::haveRight($itemtype::$rightname, self::RIGHT_READALL);
        $visible = [];
        foreach ($rows as $row) {
            if (count($visible) >= self::MAX_MATCHES) {
                $capped = true;
                break;
            }
            $id = (int) $row['id'];
            if (!$seeAll) {
                $item = new $itemtype();
                if (!$item->getFromDB($id) || !$item->canViewItem()) {
                    continue;
                }
            }
            $visible[] = $id;
        }

        return ['ids' => $visible, 'matched' => $matched, 'capped' => $capped];
    }

    private function resolveKb(): array
    {
        global $DB;

        $in = $this->filters['in'];
        // Status and followup/task filters do not apply to KB articles.
        if (!in_array($in, ['any', 'title', 'description'], true) || $this->filters['status'] !== 'any') {
            return ['ids' => [], 'matched' => [], 'capped' => false];
        }

        $where = $this->dateCriteria();
        if ($this->terms !== []) {
            $fields = $in === 'title' ? ['name'] : ($in === 'description' ? ['answer'] : ['name', 'answer']);
            $where = array_merge($where, $this->likeAll($fields));
        }
        if ($this->filters['person'] > 0) {
            $where['users_id'] = $this->filters['person'];
        }

        $matched = [];
        $visible = [];
        $capped  = false;
        foreach ($DB->request([
            'SELECT' => ['id', 'name'],
            'FROM'   => KnowbaseItem::getTable(),
            'WHERE'  => $where,
            'ORDER'  => $this->sortOrder('date_creation'),
            'LIMIT'  => self::MAX_MATCHES + 1,
        ]) as $row) {
            if (count($visible) >= self::MAX_MATCHES) {
                $capped = true;
                break;
            }
            $kb = new KnowbaseItem();
            if (!$kb->getFromDB((int) $row['id']) || !$kb->canViewItem()) {
                continue; // KB visibility targets (users / groups / entities / profiles)
            }
            $id = (int) $row['id'];
            $visible[] = $id;
            if ($this->terms !== []) {
                $matched[$id][$in !== 'any' ? $in : ($this->matchesAny(self::stored((string) $row['name'])) ? 'title' : 'description')] = true;
            }
            if ($this->filters['person'] > 0) {
                $matched[$id]['person'] = true;
            }
        }

        return ['ids' => $visible, 'matched' => $matched, 'capped' => $capped];
    }

    /**
     * IDs of ITIL items a user "touched": actor (requester / observer / assigned),
     * opened it, last updated it, or wrote a followup, task or solution on it.
     *
     * @return int[]
     */
    private function touchedBy(int $users_id, string $itemtype, string $taskclass, string $fk, string $actorclass): array
    {
        global $DB;

        $ids = [];
        $collect = function (iterable $rows, string $col) use (&$ids) {
            foreach ($rows as $row) {
                $ids[(int) $row[$col]] = true;
            }
        };
        $limit = self::MAX_MATCHES * 4;

        // actors: requester / observer / assigned
        if (class_exists($actorclass)) {
            $collect($DB->request([
                'SELECT' => [$fk],
                'FROM'   => $actorclass::getTable(),
                'WHERE'  => ['users_id' => $users_id],
                'LIMIT'  => $limit,
            ]), $fk);
        }
        // opened / last updated
        $collect($DB->request([
            'SELECT' => ['id'],
            'FROM'   => $itemtype::getTable(),
            'WHERE'  => ['is_deleted' => 0, 'OR' => [
                'users_id_recipient'   => $users_id,
                'users_id_lastupdater' => $users_id,
            ]],
            'LIMIT'  => $limit,
        ]), 'id');
        // followups they wrote (that the viewer may see)
        $collect($DB->request([
            'SELECT' => ['items_id'],
            'FROM'   => ITILFollowup::getTable(),
            'WHERE'  => array_merge(['itemtype' => $itemtype, 'users_id' => $users_id], $this->privacyCriteria('followup', false)),
            'LIMIT'  => $limit,
        ]), 'items_id');
        // tasks they wrote or were assigned (that the viewer may see)
        $collect($DB->request([
            'SELECT' => [$fk],
            'FROM'   => $taskclass::getTable(),
            'WHERE'  => array_merge(
                [['OR' => ['users_id' => $users_id, 'users_id_tech' => $users_id]]],
                $this->privacyCriteria('task', true)
            ),
            'LIMIT'  => $limit,
        ]), $fk);
        // solutions they wrote
        $collect($DB->request([
            'SELECT' => ['items_id'],
            'FROM'   => ITILSolution::getTable(),
            'WHERE'  => ['itemtype' => $itemtype, 'users_id' => $users_id],
            'LIMIT'  => $limit,
        ]), 'items_id');

        return array_keys($ids);
    }

    /**
     * SQL condition for followups/tasks the current user may read, mirroring
     * GLPI's canViewItem(): "see private" sees all; otherwise public ones (with
     * "see public") plus private ones they wrote / are assigned to.
     * Returned as a list so it can be combined with array_merge().
     */
    private function privacyCriteria(string $right, bool $isTask): array
    {
        if (Session::haveRight($right, self::RIGHT_SEEPRIVATE)) {
            return [];
        }
        $me = (int) Session::getLoginUserID();
        $or = [['is_private' => 1, 'users_id' => $me]];
        if (Session::haveRight($right, self::RIGHT_SEEPUBLIC)) {
            $or[] = ['is_private' => 0];
        }
        if ($isTask) {
            $or[] = ['is_private' => 1, 'users_id_tech' => $me];
            $groups = array_values(array_filter(array_map('intval', $_SESSION['glpigroups'] ?? [])));
            if ($groups !== []) {
                $or[] = ['is_private' => 1, 'groups_id_tech' => $groups];
            }
        }
        return [['OR' => $or]];
    }

    private function statusCriteria(string $itemtype): array
    {
        $solved = array_map('intval', $itemtype::getSolvedStatusArray());
        $closed = array_map('intval', $itemtype::getClosedStatusArray());
        switch ($this->filters['status']) {
            case 'open':
                return [['NOT' => ['status' => array_merge($solved, $closed)]]];
            case 'waiting':
                return ['status' => (int) $itemtype::WAITING];
            case 'solved':
                return ['status' => $solved];
            case 'closed':
                return ['status' => $closed];
            case 'done':
                return ['status' => array_merge($solved, $closed)];
        }
        return [];
    }

    private function dateCriteria(): array
    {
        $f = $this->filters;
        if (in_array($f['period'], ['7', '30', '90', '365'], true)) {
            $since = date('Y-m-d H:i:s', strtotime('-' . (int) $f['period'] . ' days'));
            return ['date_mod' => ['>=', $since]];
        }
        if ($f['period'] === 'custom') {
            $c = [];
            if ($f['from'] !== '') {
                $c[] = ['date_mod' => ['>=', $f['from'] . ' 00:00:00']];
            }
            if ($f['to'] !== '') {
                $c[] = ['date_mod' => ['<=', $f['to'] . ' 23:59:59']];
            }
            return $c;
        }
        return [];
    }

    /** @param string $opened column holding the creation date (ITIL: date, KB: date_creation) */
    private function sortOrder(string $opened): array
    {
        return match ($this->filters['sort']) {
            'updated_asc' => ['date_mod ASC', 'id ASC'],
            'opened_desc' => [$opened . ' DESC', 'id DESC'],
            'opened_asc'  => [$opened . ' ASC', 'id ASC'],
            default       => ['date_mod DESC', 'id DESC'],
        };
    }

    /* ------------------------------------------------------------------ */
    /* Result rows (built for the visible page only)                       */
    /* ------------------------------------------------------------------ */

    private function buildResult(string $type, int $id, array $matched): array
    {
        if ($type === 'kb') {
            $item = new KnowbaseItem();
            $item->getFromDB($id);
            $html = (string) ($item->fields['answer'] ?? '');
            $status = null;
            $itemtype = KnowbaseItem::class;
        } else {
            [$itemtype] = self::ITIL[$type];
            $item = new $itemtype();
            $item->getFromDB($id);
            $html = (string) ($item->fields['content'] ?? '');
            $status = isset($item->fields['status']) ? (int) $item->fields['status'] : null;
            // matched only in a followup/task: show that text as the snippet
            $plain = html_entity_decode(strip_tags(self::stored($html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($this->terms !== [] && !$this->matchesAny($plain)) {
                $html = $this->subItemSnippetSource($type, $id, $matched) ?? $html;
            }
        }

        return [
            'title'    => sprintf('#%d — %s', $id, self::stored((string) ($item->fields['name'] ?? ''))),
            'url'      => $item::getFormURLWithID($id),
            'matched'  => $matched,
            'snippet'  => self::snippet(self::stored($html), $this->terms),
            'date'     => (string) ($item->fields['date_mod'] ?? ''),
            'opened'   => (string) ($item->fields['date'] ?? ''),
            'status'   => $status,
            'itemtype' => $itemtype,
        ];
    }

    /** Content of the first visible followup/task on this item that contains the words. */
    private function subItemSnippetSource(string $type, int $id, array $matched): ?string
    {
        global $DB;
        [$itemtype, $taskclass, $fk] = self::ITIL[$type];

        if (in_array('followup', $matched, true)) {
            $row = $DB->request([
                'SELECT' => ['content'],
                'FROM'   => ITILFollowup::getTable(),
                'WHERE'  => array_merge(
                    ['itemtype' => $itemtype, 'items_id' => $id],
                    $this->likeAll(['content']),
                    $this->privacyCriteria('followup', false)
                ),
                'LIMIT'  => 1,
            ])->current();
            if ($row) {
                return (string) $row['content'];
            }
        }
        if (in_array('task', $matched, true)) {
            $row = $DB->request([
                'SELECT' => ['content'],
                'FROM'   => $taskclass::getTable(),
                'WHERE'  => array_merge([$fk => $id], $this->likeAll(['content']), $this->privacyCriteria('task', true)),
                'LIMIT'  => 1,
            ])->current();
            if ($row) {
                return (string) $row['content'];
            }
        }
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Every term must appear in at least one of the fields (AND of ORs).
     * Returned as a list so it can be combined with array_merge().
     */
    private function likeAll(array $fields): array
    {
        global $DB;

        $and = [];
        foreach ($this->terms as $term) {
            $needle = $term;
            if (self::isLegacy() && class_exists('Glpi\\Toolbox\\Sanitizer')) {
                // match GLPI 10's HTML-encoded storage
                $needle = \Glpi\Toolbox\Sanitizer::encodeHtmlSpecialChars($needle);
            }
            $value = '%' . addcslashes($needle, '%_\\') . '%';
            if (self::isLegacy()) {
                // GLPI 10's query builder does not escape values: do it here.
                $value = $DB->escape($value);
            }
            $or = [];
            foreach ($fields as $field) {
                $or[] = [$field => ['LIKE', $value]];
            }
            $and[] = ['OR' => $or];
        }
        return $and;
    }

    private function matchesAny(string $text): bool
    {
        foreach ($this->terms as $term) {
            if (mb_stripos($text, $term) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Plain-text excerpt centered on the first matching term.
     */
    public static function snippet(string $html, array $terms, int $radius = 90): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return '';
        }

        $pos = null;
        foreach ($terms as $term) {
            $p = mb_stripos($text, $term);
            if ($p !== false && ($pos === null || $p < $pos)) {
                $pos = $p;
            }
        }
        $start = max(0, ($pos ?? 0) - $radius);
        $len   = $radius * 2;

        return ($start > 0 ? '…' : '')
            . mb_substr($text, $start, $len)
            . ($start + $len < mb_strlen($text) ? '…' : '');
    }

    /**
     * HTML-escape text and wrap terms in <mark>, in a single pass.
     */
    public static function highlight(string $text, array $terms): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($terms === []) {
            return $escaped;
        }
        $alts = array_map(
            static fn($t) => preg_quote(htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), '/'),
            $terms
        );
        return (string) preg_replace('/' . implode('|', $alts) . '/iu', '<mark>$0</mark>', $escaped);
    }
}
