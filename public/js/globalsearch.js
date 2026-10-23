/**
 * Global Search: send the GLPI top-bar search to the plugin results page.
 * The core search stays reachable at /front/search.php if ever needed.
 */
(function () {
    'use strict';

    var root = (window.CFG_GLPI && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';
    var target = root + '/plugins/globalsearch/front/search.php';

    function findTopBarInputs() {
        // Core GLPI global search field. Skip our own results-page form.
        var inputs = document.querySelectorAll('input[name="globalsearch"]');
        return Array.prototype.filter.call(inputs, function (input) {
            var form = input.form;
            return !form || (form.getAttribute('action') || '').indexOf('/plugins/globalsearch/') === -1;
        });
    }

    function go(value) {
        var q = (value || '').trim();
        if (q.length === 0) {
            return;
        }
        window.location.href = target + '?q=' + encodeURIComponent(q);
    }

    function hook() {
        findTopBarInputs().forEach(function (input) {
            if (input.dataset.globalsearchHooked) {
                return;
            }
            input.dataset.globalsearchHooked = '1';
            input.setAttribute('placeholder', 'Search everything…');

            if (input.form) {
                input.form.addEventListener('submit', function (e) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    go(input.value);
                }, true);
            }
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    go(input.value);
                }
            }, true);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', hook);
    } else {
        hook();
    }
})();
