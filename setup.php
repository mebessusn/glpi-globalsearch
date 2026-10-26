<?php

/**
 * Global Search for GLPI 10, 11 and 12
 *
 * Replaces the top-bar search with a full-text search across tickets,
 * problems, changes (title + description + followups + tasks) and the
 * knowledge base. Every result is permission-checked per user.
 *
 * @copyright 2026 Megan Bess
 * @license   GPLv3+
 */

use Glpi\Http\Firewall;

define('PLUGIN_GLOBALSEARCH_VERSION', '0.3.0');
define('PLUGIN_GLOBALSEARCH_MIN_GLPI', '10.0.0');
define('PLUGIN_GLOBALSEARCH_MAX_GLPI', '13.0.0');

function plugin_init_globalsearch(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['globalsearch'] = true;

    // GLPI 11+: results page for standard (central) interface users only.
    // GLPI 10 has no firewall; front/search.php checks central access itself.
    if (method_exists(Firewall::class, 'addPluginStrategyForLegacyScripts')) {
        Firewall::addPluginStrategyForLegacyScripts(
            'globalsearch',
            '#^/front/search\.php$#',
            Firewall::STRATEGY_CENTRAL_ACCESS
        );
    }

    // Hook the top search bar only for central-interface sessions.
    if (Session::getLoginUserID() && Session::getCurrentInterface() === 'central') {
        // GLPI 11+ serves plugin assets from public/ (URLs without "/public");
        // GLPI 10 serves the plugin root.
        $prefix = version_compare(GLPI_VERSION, '11.0.0', '>=') ? '' : 'public/';
        $PLUGIN_HOOKS['add_javascript']['globalsearch'][] = $prefix . 'js/globalsearch.js';
    }
}

function plugin_version_globalsearch(): array
{
    return [
        'name'         => 'Global Search',
        'version'      => PLUGIN_GLOBALSEARCH_VERSION,
        'author'       => 'Megan Bess',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/mebessusn/glpi-globalsearch',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GLOBALSEARCH_MIN_GLPI,
                'max' => PLUGIN_GLOBALSEARCH_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.1',
            ],
        ],
    ];
}

function plugin_globalsearch_check_prerequisites(): bool
{
    return true;
}

function plugin_globalsearch_check_config($verbose = false): bool
{
    return true;
}
