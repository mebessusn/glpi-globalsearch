<?php

/**
 * Global Search results page.
 * URL: /plugins/globalsearch/front/search.php?q=...
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
echo '<form method="get" action="' . $e($CFG_GLPI['root_doc'] . '/plugins/globalsearch/front/search.php') . '" class="mb-3">';
echo '<div class="input-group">';
echo '<input type="search" name="q" class="form-control" autofocus value="' . $e($query) . '" placeholder="' . $e(__('Search tickets, problems, changes, followups, tasks and the knowledge base')) . '">';
echo '<button type="submit" class="btn btn-primary"><i class="ti ti-search"></i></button>';
echo '</div>';
echo '<div class="form-text">' . $e(__('Every word must appear. Hostnames like web-01.corp.local are matched as-is.')) . '</div>';
echo '</form>';

if ($query === '') {
    // nothing yet
} elseif ($terms === []) {
    echo '<div class="alert alert-info">' . $e(__('Type at least 2 characters.')) . '</div>';
} elseif ($sections === []) {
    echo '<div class="alert alert-warning">' . $e(sprintf(__('No results for "%s".'), $query)) . '</div>';
} else {
    foreach ($sections as $label => $results) {
        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title">' . $e($label)
            . ' <span class="badge bg-secondary text-white ms-2">' . count($results)
            . (count($results) >= Searcher::MAX_RESULTS ? '+' : '') . '</span></h3></div>';
        echo '<div class="list-group list-group-flush">';
        foreach ($results as $r) {
            echo '<a class="list-group-item list-group-item-action" href="' . $e($r['url']) . '">';
            echo '<div class="d-flex justify-content-between">';
            echo '<strong>' . Searcher::highlight($r['title'], $terms) . '</strong>';
            echo '<small class="text-muted">' . $e(Html::convDateTime($r['date'])) . '</small>';
            echo '</div>';
            echo '<div>';
            foreach ($r['matched'] as $m) {
                echo '<span class="badge bg-blue-lt me-1">' . $e($labels[$m] ?? $m) . '</span>';
            }
            echo '</div>';
            if ($r['snippet'] !== '') {
                echo '<div class="text-muted mt-1" style="font-size: .875em">' . Searcher::highlight($r['snippet'], $terms) . '</div>';
            }
            echo '</a>';
        }
        echo '</div></div>';
    }
}

echo '</div>';

Html::footer();
