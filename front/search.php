<?php

/**
 * Global Search results page.
 * URL: /plugins/globalsearch/front/search.php?q=...&type=ticket&status=open&person=12&page=2 ...
 */

use GlpiPlugin\Globalsearch\Searcher;

// GLPI 10 serves this file directly; GLPI 11+ boots it through its kernel
// (GLPI_ROOT already defined, firewall already applied).
if (!defined('GLPI_ROOT')) {
    include '../../../inc/includes.php';
}
Session::checkCentralAccess();

global $CFG_GLPI;

$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

// GLPI 10 HTML-encodes and SQL-escapes $_GET before plugins see it;
// work from the text the user actually typed (Searcher re-escapes for SQL).
$query = Searcher::rawInput((string) ($_GET['q'] ?? ''));
$query = mb_substr(trim($query), 0, 200);
$f     = Searcher::filtersFromRequest($_GET);

$base = $CFG_GLPI['root_doc'] . '/plugins/globalsearch/front/search.php';
$defaults = Searcher::filtersFromRequest([]);
/** Link to this page with the current state, changed by $over. Defaults are left out of the URL. */
$url = static function (array $over = []) use ($base, $query, $f, $defaults): string {
    $state = array_merge(['q' => $query], $f, ['page' => 1], $over);
    $params = [];
    foreach ($state as $k => $v) {
        if ($v === '' || $v === 0 || (isset($defaults[$k]) && $defaults[$k] === $v)) {
            continue;
        }
        if ($k !== 'period' && in_array($k, ['from', 'to'], true) && ($state['period'] ?? '') !== 'custom') {
            continue;
        }
        $params[$k] = $v;
    }
    return $base . ($params ? '?' . http_build_query($params) : '');
};

Html::header(__('Global search'), '', 'tools', 'globalsearch');

$searcher = new Searcher($query, $f);
$terms    = $searcher->getTerms();
$types    = $searcher->availableTypes();

$labels = [
    'title'       => __('Title'),
    'description' => __('Description'),
    'followup'    => __('Followup'),
    'task'        => __('Task'),
    'person'      => __('Person'),
];

$opt = static function (array $options, string $current) use ($e): string {
    $html = '';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . $e($value) . '"' . ((string) $value === $current ? ' selected' : '') . '>' . $e($label) . '</option>';
    }
    return $html;
};

echo '<div class="container-fluid globalsearch-results">';

/* ---------------- search form + filters ---------------- */
echo '<form method="get" action="' . $e($base) . '" class="mb-3" id="globalsearch-form">';
if ($f['type'] !== '') {
    echo '<input type="hidden" name="type" value="' . $e($f['type']) . '">';
}
echo '<div class="input-group">';
echo '<input type="search" name="q" class="form-control" autofocus value="' . $e($query) . '" placeholder="' . $e(__('Search tickets, problems, changes, followups, tasks and the knowledge base')) . '">';
echo '<button type="submit" class="btn btn-primary"><i class="ti ti-search"></i></button>';
echo '</div>';
echo '<div class="form-text mb-2">' . $e(__('Every word must appear. Hostnames like web-01.corp.local are matched as-is.')) . '</div>';

echo '<div class="row g-2 align-items-end globalsearch-filters">';
$field = static function (string $label, string $control, string $extra = '') use ($e): string {
    return '<div class="col-6 col-md-4 col-xl-2' . $extra . '"><label class="form-label mb-1 text-muted">' . $e($label) . '</label>' . $control . '</div>';
};
echo $field(__('Status'), '<select name="status" class="form-select form-select-sm gs-autosubmit">' . $opt([
    'any'     => __('Any status'),
    'open'    => __('Open (not solved or closed)'),
    'waiting' => __('Pending / waiting'),
    'solved'  => __('Solved'),
    'closed'  => __('Closed'),
    'done'    => __('Solved or closed'),
], $f['status']) . '</select>');
echo $field(__('Last updated'), '<select name="period" class="form-select form-select-sm gs-autosubmit" id="gs-period">' . $opt([
    'any'    => __('Any time'),
    '7'      => __('Last 7 days'),
    '30'     => __('Last 30 days'),
    '90'     => __('Last 90 days'),
    '365'    => __('Last 12 months'),
    'custom' => __('Custom range…'),
], $f['period']) . '</select>');
$hide = $f['period'] === 'custom' ? '' : ' d-none';
echo $field(__('From'), '<input type="date" name="from" class="form-control form-control-sm" value="' . $e($f['from']) . '">', ' gs-custom' . $hide);
echo $field(__('To'), '<input type="date" name="to" class="form-control form-control-sm" value="' . $e($f['to']) . '">', ' gs-custom' . $hide);
echo $field(__('Found in'), '<select name="in" class="form-select form-select-sm gs-autosubmit">' . $opt([
    'any'         => __('Anywhere'),
    'title'       => __('Title only'),
    'description' => __('Description / content'),
    'followup'    => __('Followups'),
    'task'        => __('Tasks'),
], $f['in']) . '</select>');
echo $field(__('Sort'), '<select name="sort" class="form-select form-select-sm gs-autosubmit">' . $opt([
    'updated_desc' => __('Recently updated'),
    'updated_asc'  => __('Least recently updated'),
    'opened_desc'  => __('Newest first'),
    'opened_asc'   => __('Oldest first'),
], $f['sort']) . '</select>');
echo $field(__('Per page'), '<select name="per_page" class="form-select form-select-sm gs-autosubmit">' . $opt(
    array_combine(Searcher::PER_PAGE_OPTIONS, Searcher::PER_PAGE_OPTIONS),
    (string) $f['per_page']
) . '</select>');
echo $field(
    __('Touched by (person)'),
    User::dropdown([
        'name'                => 'person',
        'value'               => $f['person'],
        'right'               => 'all',
        'display'             => false,
        'width'               => '100%',
        'display_emptychoice' => true,
    ]),
    ' col-md-8 col-xl-4'
);
echo '<div class="col-auto"><button type="submit" class="btn btn-sm btn-primary">' . $e(__('Apply')) . '</button> '
    . '<a class="btn btn-sm btn-link" href="' . $e($base . ($query !== '' ? '?' . http_build_query(['q' => $query]) : '')) . '">' . $e(__('Reset filters')) . '</a></div>';
echo '</div>'; // row
echo '</form>';

echo Html::scriptBlock(<<<JS
(function () {
    var form = document.getElementById('globalsearch-form');
    if (!form) return;
    var submit = function () { var p = form.querySelector('input[name="page"]'); if (p) p.remove(); form.submit(); };
    form.querySelectorAll('.gs-autosubmit').forEach(function (el) {
        el.addEventListener('change', function () {
            if (el.id === 'gs-period') {
                var custom = el.value === 'custom';
                form.querySelectorAll('.gs-custom').forEach(function (c) { c.classList.toggle('d-none', !custom); });
                if (custom) return; // wait for the dates
            }
            submit();
        });
    });
    if (window.jQuery) { window.jQuery(form).find('select[name="person"]').on('change', submit); }
})();
JS);

/* ---------------- person chip / people suggestions ---------------- */
if ($f['person'] > 0) {
    echo '<div class="mb-3"><span class="badge bg-azure-lt fs-5 p-2"><i class="ti ti-user me-1"></i>'
        . $e(sprintf(__('Touched by %s'), Searcher::personLabel($f['person'])))
        . ' <a class="ms-2" href="' . $e($url(['person' => 0])) . '" title="' . $e(__('Remove')) . '"><i class="ti ti-x"></i></a></span>'
        . ' <span class="text-muted ms-2">' . $e(__('Requester, observer or assigned; opened or last updated it; wrote a followup, task or solution.')) . '</span></div>';
} elseif ($terms !== []) {
    $people = $searcher->people();
    if ($people !== []) {
        echo '<div class="mb-3"><span class="text-muted me-2"><i class="ti ti-users me-1"></i>' . $e(__('People:')) . '</span>';
        foreach ($people as $person) {
            echo '<a class="btn btn-sm btn-outline-secondary me-1 mb-1" href="' . $e($url(['person' => $person['id'], 'q' => ''])) . '" title="'
                . $e(__('Show everything this person touched')) . '"><i class="ti ti-user me-1"></i>' . $e($person['label']) . '</a>';
        }
        echo '</div>';
    }
}

/* ---------------- results ---------------- */
if (!$searcher->hasCriteria()) {
    if ($query !== '') {
        echo '<div class="alert alert-info">' . $e(__('Type at least 2 characters.')) . '</div>';
    }
} elseif ($types === []) {
    echo '<div class="alert alert-warning">' . $e(__('You do not have access to any searchable item.')) . '</div>';
} else {
    $counts = [];
    foreach ($types as $t) {
        $counts[$t] = $searcher->count($t);
    }
    $active = $f['type'] !== '' && in_array($f['type'], $types, true) ? $f['type'] : null;
    if ($active === null) {
        foreach ($types as $t) {
            if ($counts[$t] > 0) {
                $active = $t;
                break;
            }
        }
        $active ??= $types[0];
    }

    if (array_sum($counts) === 0) {
        echo '<div class="alert alert-warning">' . $e(__('No results. Try fewer words or loosen the filters.')) . '</div>';
    } else {
        echo '<div class="card">';
        echo '<div class="card-header"><ul class="nav nav-tabs card-header-tabs">';
        foreach ($types as $t) {
            echo '<li class="nav-item"><a class="nav-link' . ($t === $active ? ' active' : '') . '" href="' . $e($url(['type' => $t])) . '">'
                . $e(Searcher::typeLabel($t))
                . ' <span class="badge ' . ($counts[$t] > 0 ? 'bg-secondary text-white' : 'bg-secondary-lt') . ' ms-1">'
                . $counts[$t] . ($searcher->isCapped($t) ? '+' : '') . '</span></a></li>';
        }
        echo '</ul></div>';

        $total   = $counts[$active];
        $pages   = max(1, (int) ceil($total / $f['per_page']));
        $current = min($f['page'], $pages);
        if ($current !== $f['page']) {
            $f['page'] = $current;
            $searcher->setPage($current);
        }
        $results = $total > 0 ? $searcher->page($active) : [];

        if ($active === 'kb' && $total === 0 && ($f['status'] !== 'any' || in_array($f['in'], ['followup', 'task'], true))) {
            echo '<div class="card-body text-muted">' . $e(__('Knowledge base articles have no status, followups or tasks: clear those filters to see them.')) . '</div>';
        } elseif ($total === 0) {
            echo '<div class="card-body text-muted">' . $e(__('No results in this section.')) . '</div>';
        } else {
            $from = ($current - 1) * $f['per_page'] + 1;
            $to   = min($total, $current * $f['per_page']);
            echo '<div class="card-body py-2 text-muted border-bottom">'
                . $e(sprintf(__('Showing %1$d–%2$d of %3$d'), $from, $to, $total)) . '</div>';
            if ($searcher->isCapped($active)) {
                echo '<div class="alert alert-info m-2 mb-0">' . $e(sprintf(
                    __('More than %d matches: only the first %d are listed. Add words or filters to narrow it down.'),
                    Searcher::MAX_MATCHES,
                    Searcher::MAX_MATCHES
                )) . '</div>';
            }

            echo '<div class="list-group list-group-flush">';
            foreach ($results as $r) {
                echo '<a class="list-group-item list-group-item-action" href="' . $e($r['url']) . '">';
                echo '<div class="d-flex justify-content-between align-items-start gap-3">';
                echo '<div class="d-flex align-items-center flex-wrap gap-2">';
                if ($r['status'] !== null) {
                    $itemtype = $r['itemtype'];
                    // GLPI's own colored status icon + the status name
                    echo '<span class="gs-status text-nowrap">' . $itemtype::getStatusIcon($r['status'])
                        . '<span class="gs-status-label">' . $e($itemtype::getStatus($r['status'])) . '</span></span>';
                }
                echo '<strong>' . Searcher::highlight($r['title'], $terms) . '</strong>';
                echo '</div>';
                echo '<div class="text-muted text-nowrap text-end" style="font-size: .8em">'
                    . $e(__('Updated')) . ' ' . $e(Html::convDateTime($r['date']))
                    . ($r['opened'] !== '' && $active !== 'kb' ? '<br>' . $e(__('Opened')) . ' ' . $e(Html::convDateTime($r['opened'])) : '')
                    . '</div>';
                echo '</div>';
                if ($r['matched'] !== []) {
                    echo '<div class="mt-1">';
                    foreach ($r['matched'] as $m) {
                        $label = $active === 'kb' && $m === 'description' ? __('Content') : ($labels[$m] ?? $m);
                        echo '<span class="badge bg-blue-lt me-1">' . $e($label) . '</span>';
                    }
                    echo '</div>';
                }
                if ($r['snippet'] !== '') {
                    echo '<div class="text-muted mt-1" style="font-size: .875em">' . Searcher::highlight($r['snippet'], $terms) . '</div>';
                }
                echo '</a>';
            }
            echo '</div>';

            if ($pages > 1) {
                echo '<div class="card-footer d-flex justify-content-center"><ul class="pagination m-0">';
                $link = static function (int $p, string $label, bool $disabled = false, bool $isActive = false) use ($url, $e, $active): string {
                    $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($isActive ? ' active' : '');
                    return '<li class="' . $cls . '"><a class="page-link" href="' . ($disabled ? '#' : $e($url(['type' => $active, 'page' => $p]))) . '">' . $label . '</a></li>';
                };
                echo $link(1, '<i class="ti ti-chevrons-left"></i>', $current === 1);
                echo $link(max(1, $current - 1), '<i class="ti ti-chevron-left"></i>', $current === 1);
                $window = array_unique(array_filter(
                    [1, $current - 2, $current - 1, $current, $current + 1, $current + 2, $pages],
                    static fn($p) => $p >= 1 && $p <= $pages
                ));
                sort($window);
                $prev = 0;
                foreach ($window as $p) {
                    if ($prev && $p > $prev + 1) {
                        echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                    }
                    echo $link($p, (string) $p, false, $p === $current);
                    $prev = $p;
                }
                echo $link(min($pages, $current + 1), '<i class="ti ti-chevron-right"></i>', $current === $pages);
                echo $link($pages, '<i class="ti ti-chevrons-right"></i>', $current === $pages);
                echo '</ul></div>';
            }
        }
        echo '</div>'; // card
    }
}

echo '</div>';

Html::footer();
