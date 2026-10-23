<?php

/**
 * Global Search results page.
 * URL: /plugins/globalsearch/front/search.php?q=...
 */

use GlpiPlugin\Globalsearch\Searcher;

$query = trim((string) ($_GET['q'] ?? ''));
$query = mb_substr($query, 0, 200);

Html::header(__('Global search'), '', 'tools', 'globalsearch');

$searcher = new Searcher($query);
$terms    = $searcher->getTerms();
$sections = $searcher->search();

$labels = [
    'title'       => __('Title'),
    'description' => __('Description'),
    'content'     => __('Content'),
    'followup'    => __('Followup'),
    'task'        => __('Task'),
];

echo '<div class="container-fluid globalsearch-results">';

// Search form (also usable on its own).
echo '<form method="get" action="' . htmlescape($CFG_GLPI['root_doc'] . '/plugins/globalsearch/front/search.php') . '" class="mb-3">';
echo '<div class="input-group">';
echo '<input type="search" name="q" class="form-control" autofocus value="' . htmlescape($query) . '" placeholder="' . htmlescape(__('Search tickets, problems, changes, followups, tasks and the knowledge base')) . '">';
echo '<button type="submit" class="btn btn-primary"><i class="ti ti-search"></i></button>';
echo '</div>';
echo '<div class="form-text">' . htmlescape(__('Every word must appear. Hostnames like web-01.corp.local are matched as-is.')) . '</div>';
echo '</form>';

if ($query === '') {
    // nothing yet
} elseif ($terms === []) {
    echo '<div class="alert alert-info">' . htmlescape(__('Type at least 2 characters.')) . '</div>';
} elseif ($sections === []) {
    echo '<div class="alert alert-warning">' . htmlescape(sprintf(__('No results for "%s".'), $query)) . '</div>';
} else {
    foreach ($sections as $label => $results) {
        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title">' . htmlescape($label)
            . ' <span class="badge bg-secondary ms-2">' . count($results)
            . (count($results) >= Searcher::MAX_RESULTS ? '+' : '') . '</span></h3></div>';
        echo '<div class="list-group list-group-flush">';
        foreach ($results as $r) {
            echo '<a class="list-group-item list-group-item-action" href="' . htmlescape($r['url']) . '">';
            echo '<div class="d-flex justify-content-between">';
            echo '<strong>' . Searcher::highlight($r['title'], $terms) . '</strong>';
            echo '<small class="text-muted">' . htmlescape(Html::convDateTime($r['date'])) . '</small>';
            echo '</div>';
            echo '<div>';
            foreach ($r['matched'] as $m) {
                echo '<span class="badge bg-blue-lt me-1">' . htmlescape($labels[$m] ?? $m) . '</span>';
            }
            echo '</div>';
            if ($r['snippet'] !== '') {
                echo '<div class="text-muted small mt-1">' . Searcher::highlight($r['snippet'], $terms) . '</div>';
            }
            echo '</a>';
        }
        echo '</div></div>';
    }
}

echo '</div>';

Html::footer();
