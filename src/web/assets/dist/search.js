/* global Craft */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        var wrapper = document.getElementById('cp-search-wrapper');
        if (!wrapper) return;

        var actionUrl = wrapper.getAttribute('data-action-url');
        var form = document.getElementById('cp-search-form');
        var indexInput = document.getElementById('cp-search-index');
        var queryInput = document.getElementById('cp-search-query');
        var perPageInput = document.getElementById('cp-search-per-page');
        var resultsEl = document.getElementById('cp-search-results');
        var summaryEl = document.getElementById('cp-search-summary');
        var submitBtn = form ? form.querySelector('button[type="submit"]') : null;

        if (!form || !actionUrl) return;

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            runSearch();
        });

        function runSearch() {
            var params = {
                index: indexInput.value.trim(),
                q: queryInput.value.trim(),
                perPage: perPageInput.value || 20,
            };

            if (submitBtn) submitBtn.classList.add('loading');
            summaryEl.textContent = 'Searching…';
            resultsEl.innerHTML = '';

            Craft.sendActionRequest('GET', actionUrl, { params: params })
                .then(function (response) {
                    if (submitBtn) submitBtn.classList.remove('loading');
                    renderResults(response.data);
                })
                .catch(function (error) {
                    if (submitBtn) submitBtn.classList.remove('loading');
                    var msg = (error && error.response && error.response.data && error.response.data.error)
                        || (error && error.message)
                        || 'Search failed.';
                    summaryEl.textContent = '';
                    resultsEl.innerHTML = '<p class="error">' + Craft.escapeHtml(msg) + '</p>';
                    if (Craft.cp && Craft.cp.displayError) Craft.cp.displayError(msg);
                });
        }

        function renderResults(data) {
            if (!data || typeof data !== 'object') {
                summaryEl.textContent = '';
                resultsEl.innerHTML = '<p class="zilch">No response.</p>';
                return;
            }

            if (data.error) {
                summaryEl.textContent = '';
                resultsEl.innerHTML = '<p class="error">' + Craft.escapeHtml(data.error) + '</p>';
                return;
            }

            var results = data.results || [];
            var total = data.totalResults || 0;
            var took = data.took || 0;

            summaryEl.textContent = total + ' result' + (total === 1 ? '' : 's') + ' in ' + took + 'ms';

            if (results.length === 0) {
                resultsEl.innerHTML = '<p class="zilch">No results.</p>';
                return;
            }

            var titleField = detectTitleField(results[0].source || {});

            var html = '<table class="data fullwidth"><thead><tr>'
                + '<th scope="col">ID</th>'
                + '<th scope="col">' + Craft.escapeHtml(titleField || 'Title') + '</th>'
                + '</tr></thead><tbody>';

            for (var i = 0; i < results.length; i++) {
                var row = results[i] || {};
                var id = row.id == null ? '' : String(row.id);
                var source = row.source || {};
                var title = titleField && source[titleField] != null ? String(source[titleField]) : '';
                html += '<tr>'
                    + '<td><code>' + Craft.escapeHtml(id) + '</code></td>'
                    + '<td>' + Craft.escapeHtml(title) + '</td>'
                    + '</tr>';
            }

            html += '</tbody></table>';
            resultsEl.innerHTML = html;
        }

        function detectTitleField(source) {
            var candidates = ['title', 'name', 'label', 'heading'];
            for (var i = 0; i < candidates.length; i++) {
                if (source[candidates[i]] != null) return candidates[i];
            }
            var keys = Object.keys(source);
            return keys.length ? keys[0] : null;
        }
    }
})();
