/**
 * OmniSearch: send the GLPI top-bar search to the plugin results page.
 * The core search stays reachable at /front/search.php if ever needed.
 */
(function () {
    'use strict';

    // Derive the plugin's web path from this script's own URL: GLPI 10 may serve the
    // plugin from /marketplace/omnisearch/, GLPI 11+ from /plugins/omnisearch/.
    var root = (window.CFG_GLPI && CFG_GLPI.root_doc) ? CFG_GLPI.root_doc : '';
    var target = root + '/plugins/omnisearch/front/search.php';
    var self = document.currentScript && document.currentScript.src;
    var m = self && self.match(/^(.*\/omnisearch\/)(?:public\/)?js\/omnisearch\.js/);
    if (m) {
        target = m[1] + 'front/search.php';
    }

    function findTopBarInputs() {
        // Core GLPI global search field. Skip our own results-page form.
        var inputs = document.querySelectorAll('input[name="globalsearch"]');
        return Array.prototype.filter.call(inputs, function (input) {
            var form = input.form;
            return !form || (form.getAttribute('action') || '').indexOf('/omnisearch/front/search.php') === -1;
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
            if (input.dataset.omnisearchHooked) {
                return;
            }
            input.dataset.omnisearchHooked = '1';

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
