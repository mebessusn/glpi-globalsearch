<?php

/**
 * Global Search for GLPI 11
 *
 * Replaces the top-bar search with a full-text search across tickets,
 * problems, changes (title + description + followups + tasks) and the
 * knowledge base. Every result is permission-checked per user.
 *
 * @copyright 2026 Megan Bess
 * @license   GPLv3+
 */

use Glpi\Http\Firewall;

define('PLUGIN_GLOBALSEARCH_VERSION', '0.1.0');
define('PLUGIN_GLOBALSEARCH_MIN_GLPI', '11.0.0');
define('PLUGIN_GLOBALSEARCH_MAX_GLPI', '11.99.99');

function plugin_init_globalsearch(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['globalsearch'] = true;

    // Results page: standard (central) interface users only.
    Firewall::addPluginStrategyForLegacyScripts(
        'globalsearch',
        '#^/front/search\.php$#',
        Firewall::STRATEGY_CENTRAL_ACCESS
    );

    // Hook the top search bar only for central-interface sessions.
    if (Session::getLoginUserID() && Session::getCurrentInterface() === 'central') {
        $PLUGIN_HOOKS['add_javascript']['globalsearch'][] = 'js/globalsearch.js';
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
                'min' => '8.2',
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
