/**
 * MC-EMS Base – Metabox User Search
 *
 * Provides searchable user-selection fields for:
 *  - Proctor        (tutor_instructor role only, search by name or email)
 *  - Associated Candidate ♿ (all users, search by name or email)
 *
 * Both fields use the same REST endpoints that run two WP_User_Query calls
 * (one for display_name, one for user_email), merge and deduplicate results,
 * and return up to 20 matches as JSON: [{id, name, email}, ...].
 *
 * Depends on: MCEMS_USER_SEARCH (localised via wp_localize_script)
 *   - MCEMS_USER_SEARCH.restUrl  – base REST URL, e.g. /wp-json/mcems/v1/
 *   - MCEMS_USER_SEARCH.nonce    – WP REST nonce (X-WP-Nonce header)
 *   - MCEMS_USER_SEARCH.i18n.*   – translatable strings
 *     - i18n.noResults           – shown when no users match the query
 */
/* global MCEMS_USER_SEARCH */
(function () {
    'use strict';

    if (typeof MCEMS_USER_SEARCH === 'undefined') return;

    var restUrl  = MCEMS_USER_SEARCH.restUrl  || '';
    var nonce    = MCEMS_USER_SEARCH.nonce    || '';
    var i18nData = MCEMS_USER_SEARCH.i18n     || {};

    function i18n(key, fallback) {
        return i18nData[key] || fallback || key;
    }

    /**
     * Perform a GET request to a REST endpoint with WP REST authentication.
     *
     * @param {string} url – Full URL including query string.
     * @returns {Promise<any>} Resolves with parsed JSON response.
     */
    function restGet(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': nonce }
        }).then(function (r) { return r.json(); });
    }

    /**
     * Initialise a user-search widget.
     *
     * The widget debounces keyboard input (300 ms), fires a REST request with
     * the search query, and shows a styled dropdown of matching users.
     * Both name/surname and email are searched; results are deduplicated.
     * Keyboard navigation (↑ ↓ Enter Escape) is fully supported.
     *
     * @param {Object}  cfg
     * @param {string}  cfg.inputId   – ID of the visible text search input.
     * @param {string}  cfg.hiddenId  – ID of the hidden input storing the selected user ID.
     * @param {string}  cfg.resultsId – ID of the element used as the dropdown container.
     * @param {string}  cfg.selectedId – ID of the element that displays the selected user.
     * @param {string}  cfg.clearId   – ID of the button that clears the current selection.
     * @param {string}  cfg.endpoint  – REST API URL without query string
     *                                  (e.g. restUrl + 'search-proctors').
     * @param {boolean} cfg.disabled  – When true, the widget is rendered read-only.
     */
    function initSearch(cfg) {
        var input    = document.getElementById(cfg.inputId);
        var hidden   = document.getElementById(cfg.hiddenId);
        var results  = document.getElementById(cfg.resultsId);
        var selected = document.getElementById(cfg.selectedId);
        var clearBtn = document.getElementById(cfg.clearId);

        if (!input || !hidden || !results || !selected) return;

        var timer = null;
        var currentQuery = '';

        function showSelected(id, name, email) {
            hidden.value = id || 0;
            if (id && id !== '0') {
                selected.innerHTML =
                    '<span class="mcems-user-selected-name">' + escHtml(name) + '</span>' +
                    ' <span class="mcems-user-selected-email">(' + escHtml(email) + ')</span>';
                if (clearBtn) clearBtn.style.display = 'inline-block';
            } else {
                selected.innerHTML = '';
                if (clearBtn) clearBtn.style.display = 'none';
            }
            input.value = '';
            closeResults();
        }

        function closeResults() {
            results.innerHTML = '';
            results.style.display = 'none';
        }

        function openResults(users) {
            results.innerHTML = '';
            if (!users || !users.length) {
                var noRes = document.createElement('div');
                noRes.className = 'mcems-user-item mcems-user-item-empty';
                noRes.textContent = i18n('noResults', 'No users found.');
                results.appendChild(noRes);
                results.style.display = 'block';
                return;
            }
            users.forEach(function (u) {
                var item = document.createElement('div');
                item.className = 'mcems-user-item';
                item.setAttribute('data-id', u.id);
                item.setAttribute('data-name', u.name);
                item.setAttribute('data-email', u.email);
                item.innerHTML =
                    '<strong>' + escHtml(u.name) + '</strong>' +
                    ' <span class="mcems-user-item-email">' + escHtml(u.email) + '</span>';
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault(); // prevent input blur before click fires
                    showSelected(u.id, u.name, u.email);
                });
                results.appendChild(item);
            });
            results.style.display = 'block';
        }

        function doSearch(q) {
            currentQuery = q;
            var url = cfg.endpoint + '?q=' + encodeURIComponent(q);
            restGet(url).then(function (data) {
                if (currentQuery !== q) return; // stale response
                openResults(Array.isArray(data) ? data : []);
            }).catch(function () {
                closeResults();
            });
        }

        // Typing in the search box
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2) {
                closeResults();
                return;
            }
            timer = setTimeout(function () { doSearch(q); }, 300);
        });

        // Hide results when focus leaves the widget area
        input.addEventListener('blur', function () {
            setTimeout(closeResults, 200);
        });

        // Clear button
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                showSelected(0, '', '');
            });
        }

        // Keyboard navigation
        input.addEventListener('keydown', function (e) {
            var items = Array.from(results.querySelectorAll('.mcems-user-item:not(.mcems-user-item-empty)'));
            if (!items.length) return;

            var focused = results.querySelector('.mcems-user-item.focused');
            var idx = items.indexOf(focused);

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (focused) focused.classList.remove('focused');
                var next = items[idx + 1] || items[0];
                next.classList.add('focused');
                next.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (focused) focused.classList.remove('focused');
                var prev = items[idx - 1] || items[items.length - 1];
                prev.classList.add('focused');
                prev.scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (focused) {
                    showSelected(
                        focused.getAttribute('data-id'),
                        focused.getAttribute('data-name'),
                        focused.getAttribute('data-email')
                    );
                }
            } else if (e.key === 'Escape') {
                closeResults();
            }
        });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var cfg = MCEMS_USER_SEARCH;

        initSearch({
            inputId:   'mcems_proctor_search',
            hiddenId:  'mcems_proctor_user_id',
            resultsId: 'mcems_proctor_results',
            selectedId: 'mcems_proctor_selected',
            clearId:   'mcems_proctor_clear',
            endpoint:  restUrl + 'search-proctors',
            disabled:  cfg.disabled
        });

        initSearch({
            inputId:   'mcems_candidate_search',
            hiddenId:  'mcems_special_user_id',
            resultsId: 'mcems_candidate_results',
            selectedId: 'mcems_candidate_selected',
            clearId:   'mcems_candidate_clear',
            endpoint:  restUrl + 'search-candidates',
            disabled:  cfg.disabled
        });
    });

})();
