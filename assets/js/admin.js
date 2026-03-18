/**
 * MC-EMS Base – Admin JavaScript
 *
 * Handles:
 *  1. Bulk actions (select-all, confirm, AJAX submit)
 *  2. Admin table sorting
 *  3. Admin filters (date range, exam, status)
 *  4. Modals (open, close, form handling)
 *  5. Inline editing of session fields
 *  6. Export CSV
 *  7. Admin banner dismiss
 *
 * Depends on: MCEMS_ADMIN (localised via wp_localize_script)
 *   - MCEMS_ADMIN.ajaxUrl
 *   - MCEMS_ADMIN.nonce
 *   - MCEMS_ADMIN.i18n.*
 */
/* global MCEMS_ADMIN */
(function () {
    'use strict';

    /* ----------------------------------------------------------
       Helpers
       ---------------------------------------------------------- */
    function qs(sel, root) {
        return (root || document).querySelector(sel);
    }

    function qsa(sel, root) {
        return Array.from((root || document).querySelectorAll(sel));
    }

    /**
     * POST via Fetch to admin-ajax.php.
     * @param {string} action  WP AJAX action
     * @param {Object} data    Key/value pairs to append
     * @returns {Promise<Object>} Parsed JSON response
     */
    function ajaxPost(action, data) {
        if (typeof MCEMS_ADMIN === 'undefined') {
            return Promise.reject(new Error('MCEMS_ADMIN not defined'));
        }
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', MCEMS_ADMIN.nonce);
        Object.keys(data || {}).forEach(function (k) {
            fd.append(k, data[k]);
        });
        return fetch(MCEMS_ADMIN.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function (r) { return r.json(); });
    }

    function i18n(key, fallback) {
        if (typeof MCEMS_ADMIN !== 'undefined' && MCEMS_ADMIN.i18n && MCEMS_ADMIN.i18n[key]) {
            return MCEMS_ADMIN.i18n[key];
        }
        return fallback || key;
    }

    /* ----------------------------------------------------------
       1. Bulk Actions
       ---------------------------------------------------------- */
    function initBulkActions() {
        var bar = qs('.mcems-bulk-actions-bar');
        if (!bar) return;

        var selectAll = qs('#mcems-select-all');
        var checkboxes = qsa('input.mcems-row-cb');
        var actionSelect = qs('#mcems-bulk-action-select', bar);
        var applyBtn = qs('#mcems-bulk-action-apply', bar);

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                checkboxes.forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
            });

            // Keep "select all" in sync
            checkboxes.forEach(function (cb) {
                cb.addEventListener('change', function () {
                    selectAll.checked = checkboxes.every(function (c) { return c.checked; });
                });
            });
        }

        if (applyBtn && actionSelect) {
            applyBtn.addEventListener('click', function () {
                var action = actionSelect.value;
                if (!action) {
                    alert(i18n('selectAction', 'Please select an action.'));
                    return;
                }

                var selected = checkboxes
                    .filter(function (cb) { return cb.checked; })
                    .map(function (cb) { return cb.value; });

                if (!selected.length) {
                    alert(i18n('selectItems', 'Please select at least one item.'));
                    return;
                }

                var confirmMsg = i18n('confirmBulk', 'Apply action to {count} item(s)?');
                if (!confirm(confirmMsg.replace('{count}', selected.length))) return;

                applyBtn.disabled = true;

                ajaxPost('mcems_bulk_action', {
                    bulk_action: action,
                    ids: JSON.stringify(selected)
                }).then(function (res) {
                    applyBtn.disabled = false;
                    if (res && res.success) {
                        window.location.reload();
                    } else {
                        alert((res && res.data && res.data.message) ? res.data.message : i18n('error', 'An error occurred.'));
                    }
                }).catch(function () {
                    applyBtn.disabled = false;
                    alert(i18n('networkError', 'Network error. Please try again.'));
                });
            });
        }
    }

    /* ----------------------------------------------------------
       2. Admin Table Sorting (client-side for small tables)
       ---------------------------------------------------------- */
    function initTableSort() {
        qsa('.mcems-admin-table th.sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var table = th.closest('table');
                if (!table) return;

                var colIndex = Array.from(th.parentElement.children).indexOf(th);
                var tbody = table.querySelector('tbody');
                if (!tbody) return;

                var rows = Array.from(tbody.querySelectorAll('tr'));
                var asc = !th.classList.contains('sort-asc');

                // Clear all sort indicators
                qsa('th.sortable', table).forEach(function (h) {
                    h.classList.remove('sort-asc', 'sort-desc');
                });
                th.classList.add(asc ? 'sort-asc' : 'sort-desc');

                rows.sort(function (a, b) {
                    var aText = (a.cells[colIndex] ? a.cells[colIndex].textContent : '').trim();
                    var bText = (b.cells[colIndex] ? b.cells[colIndex].textContent : '').trim();
                    var cmp = aText.localeCompare(bText, undefined, { numeric: true });
                    return asc ? cmp : -cmp;
                });

                rows.forEach(function (row) { tbody.appendChild(row); });
            });
        });
    }

    /* ----------------------------------------------------------
       3. Admin Filters
       ---------------------------------------------------------- */
    function initAdminFilters() {
        var filterBar = qs('.mcems-admin-filters');
        if (!filterBar) return;

        var clearBtn = qs('.mcems-filter-clear', filterBar);
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                qsa('input, select', filterBar).forEach(function (el) {
                    if (el.tagName === 'SELECT') {
                        el.selectedIndex = 0;
                    } else {
                        el.value = '';
                    }
                });
                // Re-submit the filter form if present
                var form = filterBar.closest('form');
                if (form) form.submit();
            });
        }
    }

    /* ----------------------------------------------------------
       4. Modals
       ---------------------------------------------------------- */
    function initModals() {
        // Open modal
        qsa('[data-mcems-modal-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-mcems-modal-open');
                var overlay = qs('#' + targetId);
                if (overlay) {
                    overlay.classList.add('open');
                    var firstInput = qs('input, select, textarea', overlay);
                    if (firstInput) firstInput.focus();
                }
            });
        });

        // Close modal via .mcems-modal-close or clicking the overlay background
        qsa('.mcems-modal-overlay').forEach(function (overlay) {
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) {
                    overlay.classList.remove('open');
                }
            });

            qsa('.mcems-modal-close', overlay).forEach(function (btn) {
                btn.addEventListener('click', function () {
                    overlay.classList.remove('open');
                });
            });
        });

        // Close on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                qsa('.mcems-modal-overlay.open').forEach(function (overlay) {
                    overlay.classList.remove('open');
                });
            }
        });
    }

    /* ----------------------------------------------------------
       5. Inline Editing
       ---------------------------------------------------------- */
    function initInlineEditing() {
        qsa('[data-mcems-inline-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var rowId = btn.getAttribute('data-mcems-inline-edit');
                var wrap = qs('#mcems-inline-' + rowId);
                if (!wrap) return;

                var isVisible = wrap.classList.contains('visible');
                // Close all open inline forms first
                qsa('.mcems-inline-edit-wrap.visible').forEach(function (el) {
                    el.classList.remove('visible');
                });

                if (!isVisible) {
                    wrap.classList.add('visible');
                    var firstInput = qs('input', wrap);
                    if (firstInput) firstInput.focus();
                }
            });
        });

        // Cancel inline editing
        qsa('.mcems-inline-edit-cancel').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wrap = btn.closest('.mcems-inline-edit-wrap');
                if (wrap) wrap.classList.remove('visible');
            });
        });

        // Save inline editing via AJAX
        qsa('.mcems-inline-edit-save').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wrap = btn.closest('.mcems-inline-edit-wrap');
                if (!wrap) return;

                var itemId = wrap.getAttribute('data-mcems-item-id');
                var errEl = qs('.mcems-inline-edit-error', wrap);
                var fields = {};

                qsa('input, select', wrap).forEach(function (input) {
                    if (input.name) fields[input.name] = input.value;
                });

                btn.disabled = true;

                ajaxPost('mcems_inline_edit_save', Object.assign({ item_id: itemId }, fields))
                    .then(function (res) {
                        btn.disabled = false;
                        if (res && res.success) {
                            wrap.classList.remove('visible');
                            window.location.reload();
                        } else {
                            var msg = (res && res.data && res.data.message) ? res.data.message : i18n('error', 'Save failed.');
                            if (errEl) {
                                errEl.textContent = msg;
                                errEl.classList.add('visible');
                            } else {
                                alert(msg);
                            }
                        }
                    })
                    .catch(function () {
                        btn.disabled = false;
                        var msg = i18n('networkError', 'Network error.');
                        if (errEl) {
                            errEl.textContent = msg;
                            errEl.classList.add('visible');
                        } else {
                            alert(msg);
                        }
                    });
            });
        });
    }

    /* ----------------------------------------------------------
       6. Export CSV
       ---------------------------------------------------------- */
    function initExportCsv() {
        var exportBtn = qs('#mcems-export-csv');
        if (!exportBtn) return;

        exportBtn.addEventListener('click', function () {
            exportBtn.disabled = true;
            exportBtn.textContent = i18n('exporting', 'Exporting…');

            // Collect current filter params if any
            var params = new URLSearchParams(window.location.search);
            params.set('mcems_export', 'csv');
            params.set('mcems_export_nonce', typeof MCEMS_ADMIN !== 'undefined' ? MCEMS_ADMIN.exportNonce || '' : '');

            window.location.href = window.location.pathname + '?' + params.toString();

            // Re-enable after a short delay
            setTimeout(function () {
                exportBtn.disabled = false;
                exportBtn.textContent = i18n('exportCsv', 'Export CSV');
            }, 2000);
        });
    }

    /* ----------------------------------------------------------
       7. Admin Banner Dismiss
       ---------------------------------------------------------- */
    function initBannerDismiss() {
        qsa('.mcems-banner-dismiss').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var banner = btn.closest('.mcems-admin-banner, .notice');
                if (!banner) return;

                // Fade out
                banner.style.transition = 'opacity 0.3s ease';
                banner.style.opacity = '0';
                setTimeout(function () {
                    banner.style.display = 'none';
                }, 320);

                // Persist dismiss via AJAX
                var bannerKey = btn.getAttribute('data-banner-key') || '';
                var nonce = btn.getAttribute('data-nonce') || (typeof MCEMS_ADMIN !== 'undefined' ? MCEMS_ADMIN.nonce : '');

                if (bannerKey) {
                    if (typeof MCEMS_ADMIN === 'undefined') return;
                    var fd = new FormData();
                    fd.append('action', 'mcems_dismiss_banner');
                    fd.append('banner_key', bannerKey);
                    fd.append('nonce', nonce);
                    fetch(MCEMS_ADMIN.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: fd
                    });
                }
            });
        });
    }

    /* ----------------------------------------------------------
       Init
       ---------------------------------------------------------- */
    document.addEventListener('DOMContentLoaded', function () {
        initBulkActions();
        initTableSort();
        initAdminFilters();
        initModals();
        initInlineEditing();
        initExportCsv();
        initBannerDismiss();
    });

})();
