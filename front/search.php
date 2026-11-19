<?php

/**
 * OmniSearch results page.
 * URL: <plugin web dir>/front/search.php?q=...&type=ticket&status=open&person=12&page=2 ...
 *
 * This file only prepares data; templates/search.html.twig renders it (auto-escaped).
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Omnisearch\Searcher;

// GLPI 10 serves this file directly; GLPI 11+ boots it through its kernel
// (GLPI_ROOT already defined, firewall already applied).
if (!defined('GLPI_ROOT')) {
    include '../../../inc/includes.php';
}
Session::checkCentralAccess();

global $CFG_GLPI;

// the plugin's own sentences use the plugin's text domain (locales/*.mo)
$t = static fn(string $s): string => __($s, 'omnisearch');

// GLPI 10 HTML-encodes and SQL-escapes $_GET before plugins see it;
// work from the text the user actually typed (Searcher re-escapes for SQL).
$query = Searcher::rawInput((string) ($_GET['q'] ?? ''));
$query = mb_substr(trim($query), 0, 200);
$f     = Searcher::filtersFromRequest($_GET);

// "Touched by": only a user this session may see (else the name and the
// dropdown's contact card of any user ID would be shown).
if ($f['person'] > 0) {
    $person = new User();
    if (!$person->getFromDB($f['person']) || !$person->canViewItem()) {
        $f['person'] = 0;
    }
}

// GLPI 10 installs Marketplace plugins under /marketplace/, GLPI 11+ serves /plugins/.
$base = (version_compare(GLPI_VERSION, '11.0.0', '>=')
    ? $CFG_GLPI['root_doc'] . '/plugins/omnisearch'
    : Plugin::getWebDir('omnisearch')) . '/front/search.php';
$defaults = Searcher::filtersFromRequest([]);
/** Link to this page with the current state, changed by $over. Defaults are left out of the URL. */
$url = static function (array $over = []) use ($base, $query, $f, $defaults): string {
    $state  = array_merge(['q' => $query], $f, ['page' => 1], $over);
    $params = [];
    foreach ($state as $k => $v) {
        if ($v === '' || $v === 0 || (isset($defaults[$k]) && $defaults[$k] === $v)) {
            continue;
        }
        if (in_array($k, ['from', 'to'], true) && ($state['period'] ?? '') !== 'custom') {
            continue;
        }
        $params[$k] = $v;
    }
    return $base . ($params ? '?' . http_build_query($params) : '');
};

$searcher = new Searcher($query, $f);
$terms    = $searcher->getTerms();
$types    = $searcher->availableTypes();

$labels = [
    'title'       => __('Title'),
    'description' => __('Description'),
    'followup'    => __('Followup'),
    'task'        => __('Task'),
    'person'      => __('Person'),
    'login'       => __('Login'),
    'serial'      => __('Serial number'),
    'inventory'   => __('Inventory number'),
    'contact'     => __('Alternate username'),
    'comment'     => __('Comments'),
    'ip'          => __('IP address'),
    'mac'         => __('MAC address'),
    'email'       => __('Email'),
    'phone'       => __('Phone'),
    'employee'    => __('Administrative number'),
    'filename'    => __('File'),
    'code'        => __('Code'),
];

$vars = [
    'base'      => $base,
    'query'     => $query,
    'filters'   => $f,
    'reset_url' => $base . ($query !== '' ? '?' . http_build_query(['q' => $query]) : ''),
    'selects'   => [
        ['name' => 'status', 'label' => __('Status'), 'current' => $f['status'], 'options' => [
            'any'     => $t('Any status'),
            'open'    => $t('Open (not solved or closed)'),
            'waiting' => $t('Pending / waiting'),
            'solved'  => __('Solved'),
            'closed'  => __('Closed'),
            'done'    => $t('Solved or closed'),
        ]],
        ['name' => 'period', 'label' => $t('Last updated'), 'current' => $f['period'], 'options' => [
            'any'    => $t('Any time'),
            '7'      => $t('Last 7 days'),
            '30'     => $t('Last 30 days'),
            '90'     => $t('Last 90 days'),
            '365'    => $t('Last 12 months'),
            'custom' => $t('Custom range…'),
        ]],
        ['name' => 'in', 'label' => $t('Found in'), 'current' => $f['in'], 'options' => [
            'any'         => $t('Anywhere'),
            'title'       => $t('Title only'),
            'description' => $t('Description / content'),
            'followup'    => $t('Followups'),
            'task'        => $t('Tasks'),
        ]],
        ['name' => 'sort', 'label' => __('Sort'), 'current' => $f['sort'], 'options' => [
            'updated_desc' => $t('Recently updated'),
            'updated_asc'  => $t('Least recently updated'),
            'opened_desc'  => $t('Newest first'),
            'opened_asc'   => $t('Oldest first'),
        ]],
        ['name' => 'per_page', 'label' => $t('Per page'), 'current' => (string) $f['per_page'],
            'options' => array_combine(Searcher::PER_PAGE_OPTIONS, Searcher::PER_PAGE_OPTIONS)],
    ],
    'person_dropdown' => User::dropdown([
        'name'                => 'person',
        'value'               => $f['person'],
        'right'               => 'all',
        'display'             => false,
        'width'               => '100%',
        'display_emptychoice' => true,
    ]),
    'script' => Html::scriptBlock(<<<JS
(function () {
    var form = document.getElementById('omnisearch-form');
    if (!form) return;
    var submit = function () { var p = form.querySelector('input[name="page"]'); if (p) p.remove(); form.submit(); };
    form.querySelectorAll('.gs-autosubmit').forEach(function (el) {
        el.addEventListener('change', function () {
            if (el.name === 'period') {
                var custom = el.value === 'custom';
                form.querySelectorAll('.gs-custom').forEach(function (c) { c.classList.toggle('d-none', !custom); });
                if (custom) return; // wait for the dates
            }
            submit();
        });
    });
    if (window.jQuery) { window.jQuery(form).find('select[name="person"]').on('change', submit); }
})();
JS),
    'person'        => null,
    'people'        => [],
    'state'         => 'none',
    'tabs'          => [],
    'note'          => null,
    'rows'          => [],
    'pager'         => [],
    'showing'       => null,
    'capped_notice' => false,
    'max_matches'   => Searcher::MAX_MATCHES,
];

/* ---------------- person chip / people suggestions ---------------- */
if ($f['person'] > 0) {
    $vars['person'] = [
        'label'      => Searcher::personLabel($f['person']),
        'remove_url' => $url(['person' => 0]),
    ];
} elseif ($terms !== []) {
    foreach ($searcher->people() as $p) {
        $vars['people'][] = ['label' => $p['label'], 'url' => $url(['person' => $p['id'], 'q' => ''])];
    }
}

/* ---------------- results ---------------- */
if (!$searcher->hasCriteria()) {
    $vars['state'] = $query !== '' ? 'short' : 'none';
} elseif ($types === []) {
    $vars['state'] = 'noaccess';
} else {
    // The displayed tab is resolved exactly; the others only get a bounded count.
    $active = $f['type'] !== '' && in_array($f['type'], $types, true) ? $f['type'] : null;
    if ($active === null) {
        foreach ($types as $type) {
            if ($searcher->count($type, false) > 0 || $searcher->isCapped($type, false)) {
                $active = $type;
                break;
            }
        }
        $active ??= $types[0];
    }

    $any = false;
    foreach ($types as $type) {
        $exact  = $type === $active;
        $count  = $searcher->count($type, $exact);
        $capped = $searcher->isCapped($type, $exact);
        $any    = $any || $count > 0 || $capped;
        $vars['tabs'][] = [
            'label'  => Searcher::typeLabel($type),
            'count'  => $count,
            'capped' => $capped,
            'url'    => $url(['type' => $type]),
            'active' => $exact,
        ];
    }

    if (!$any) {
        $vars['state'] = 'noresults';
    } else {
        $vars['state'] = 'results';
        $total   = $searcher->count($active);
        $pages   = max(1, (int) ceil($total / $f['per_page']));
        $current = min($f['page'], $pages);
        if ($current !== $f['page']) {
            $f['page'] = $current;
            $searcher->setPage($current);
        }

        $ticketOnly = $f['status'] !== 'any' || in_array($f['in'], ['followup', 'task'], true);
        if ($total === 0 && ($active === 'kb' || Searcher::isCatalogType($active)) && $ticketOnly) {
            $vars['note'] = sprintf(
                $t('%s: these items have no ticket status, followups or tasks. Clear the Status / Found in filters to see them.'),
                Searcher::typeLabel($active)
            );
        } elseif ($total === 0 && $active === 'user' && $f['person'] > 0) {
            $vars['note'] = $t('The "Touched by" filter does not apply to users: remove it to search users.');
        } elseif ($total === 0) {
            $vars['note'] = $t('No results in this section.');
        } else {
            $vars['showing'] = [
                'from'  => ($current - 1) * $f['per_page'] + 1,
                'to'    => min($total, $current * $f['per_page']),
                'total' => $total,
            ];
            $vars['capped_notice'] = $searcher->isCapped($active);

            foreach ($searcher->page($active) as $r) {
                $details = [];
                foreach ($r['details'] ?? [] as $label => $value) {
                    $details[] = ['label' => $label, 'html' => Searcher::highlight((string) $value, $terms)];
                }
                $matched = [];
                foreach ($r['matched'] as $m) {
                    $matched[] = $active === 'kb' && $m === 'description' ? __('Content') : ($labels[$m] ?? $m);
                }
                $itemtype = $r['itemtype'];
                $vars['rows'][] = [
                    'url'          => $r['url'],
                    'typename'     => $r['typename'] ?? '',
                    'icon'         => $r['icon'] ?? '',
                    // GLPI's own colored status icon + the status name (tickets, problems, changes)
                    'status_icon'  => $r['status'] !== null ? $itemtype::getStatusIcon($r['status']) : null,
                    'status_label' => $r['status'] !== null ? $itemtype::getStatus($r['status']) : '',
                    'title_html'   => Searcher::highlight($r['title'], $terms),
                    'badges'       => $r['badges'] ?? [],
                    'date'         => Html::convDateTime($r['date']),
                    'opened'       => $r['opened'] !== '' && $active !== 'kb' ? Html::convDateTime($r['opened']) : '',
                    'matched'      => $matched,
                    'details'      => $details,
                    'snippet_html' => $r['snippet'] !== '' ? Searcher::highlight($r['snippet'], $terms) : '',
                ];
            }

            if ($pages > 1) {
                $link = static fn(int $p, array $extra = []) => array_merge([
                    'url' => $url(['type' => $active, 'page' => $p]), 'label' => (string) $p,
                    'icon' => null, 'title' => null, 'disabled' => false, 'active' => false, 'ellipsis' => false,
                ], $extra);
                $vars['pager'][] = $link(1, ['icon' => 'ti-chevrons-left', 'title' => $t('First page'), 'disabled' => $current === 1]);
                $vars['pager'][] = $link(max(1, $current - 1), ['icon' => 'ti-chevron-left', 'title' => $t('Previous page'), 'disabled' => $current === 1]);
                $window = array_unique(array_filter(
                    [1, $current - 2, $current - 1, $current, $current + 1, $current + 2, $pages],
                    static fn($p) => $p >= 1 && $p <= $pages
                ));
                sort($window);
                $prev = 0;
                foreach ($window as $p) {
                    if ($prev && $p > $prev + 1) {
                        $vars['pager'][] = ['ellipsis' => true];
                    }
                    $vars['pager'][] = $link($p, ['active' => $p === $current]);
                    $prev = $p;
                }
                $vars['pager'][] = $link(min($pages, $current + 1), ['icon' => 'ti-chevron-right', 'title' => $t('Next page'), 'disabled' => $current === $pages]);
                $vars['pager'][] = $link($pages, ['icon' => 'ti-chevrons-right', 'title' => $t('Last page'), 'disabled' => $current === $pages]);
            }
        }
    }
}

Html::header('OmniSearch', '', 'tools', 'omnisearch');
TemplateRenderer::getInstance()->display('@omnisearch/search.html.twig', $vars);
Html::footer();
