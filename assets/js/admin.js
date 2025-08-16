(function () {
    // Helpers
    function qs(s) { return document.querySelector(s); }
    function byId(id) { return document.getElementById(id); }
    function setText(el, text) { if (el) el.textContent = text; }
    function busy(btn, on) { if (!btn) return; btn.disabled = !!on; btn.dataset.busy = on ? '1' : '0'; }

    function baseUrl() { return (window.AUTOMIND_ADMIN && AUTOMIND_ADMIN.restUrl) ? AUTOMIND_ADMIN.restUrl : '/wp-json/automind/v1/'; }
    function nonce() { return (window.AUTOMIND_ADMIN && AUTOMIND_ADMIN.nonce) ? AUTOMIND_ADMIN.nonce : ''; }

    function fetchJSON(endpoint, opts) {
        opts = opts || {};
        var headers = Object.assign({
            'X-WP-Nonce': nonce()
        }, opts.headers || {});
        return fetch(baseUrl() + endpoint, Object.assign({}, opts, { headers: headers }));
    }

    // ============ USTAWIENIA ============
    function bindModels() {
        var btn = byId('am-refresh-models');
        var sel = byId('automind_model');
        var stat = byId('am-models-status');
        if (!btn || !sel) return;

        btn.addEventListener('click', function () {
            busy(btn, true); setText(stat, 'Pobieram…');
            fetchJSON('models?refresh=1')
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    busy(btn, false);
                    if (!j || !j.ok) { setText(stat, 'Błąd: ' + (j && (j.error || j.message) ? (j.error || j.message) : 'unknown')); return; }
                    var current = sel.value;
                    sel.innerHTML = '';
                    (j.models || []).forEach(function (id) {
                        var opt = document.createElement('option');
                        opt.value = id; opt.textContent = id;
                        if (id === current) opt.selected = true;
                        sel.appendChild(opt);
                    });
                    if (!sel.value && j.models && j.models.length) { sel.value = j.models[0]; }
                    setText(stat, 'Załadowano ' + (j.models ? j.models.length : 0));
                })
                .catch(function (err) {
                    busy(btn, false); setText(stat, 'Błąd: ' + (err && err.message ? err.message : 'unknown'));
                });
        });
    }

    function bindTest() {
        var btn = byId('am-test-openai');
        var stat = byId('am-test-status');
        if (!btn) return;

        btn.addEventListener('click', function () {
            busy(btn, true); setText(stat, 'Testuję…');
            fetchJSON('test', { method: 'POST' })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    busy(btn, false);
                    if (j && j.ok) { setText(stat, 'OK (' + (j.model || '') + ')'); }
                    else { setText(stat, 'Błąd: ' + (j && (j.error || j.message) ? (j.error || j.message) : 'unknown')); }
                })
                .catch(function (err) {
                    busy(btn, false); setText(stat, 'Błąd: ' + (err && err.message ? err.message : 'unknown'));
                });
        });
    }

    function bindBearer() {
        var inp = byId('am-bearer-secret');
        var tog = byId('am-toggle-bearer');
        var cpy = byId('am-copy-bearer');
        var reg = byId('am-regenerate-bearer');
        var stat = byId('am-bearer-status');

        if (tog && inp) {
            tog.addEventListener('click', function () {
                inp.type = (inp.type === 'password') ? 'text' : 'password';
                tog.textContent = (inp.type === 'password') ? 'Pokaż' : 'Ukryj';
            });
        }
        if (cpy && inp) {
            cpy.addEventListener('click', function () {
                var prev = inp.type; inp.type = 'text'; inp.select();
                document.execCommand('copy');
                inp.type = prev;
                setText(stat, 'Skopiowano'); setTimeout(function () { setText(stat, ''); }, 1500);
            });
        }
        if (reg) {
            reg.addEventListener('click', function () {
                reg.disabled = true; setText(stat, 'Generuję…');
                fetchJSON('bearer/regenerate', { method: 'POST' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        reg.disabled = false;
                        if (j && j.ok && j.secret) {
                            if (inp) { inp.value = j.secret; }
                            setText(stat, 'Zmieniono'); setTimeout(function () { setText(stat, ''); }, 1500);
                        } else {
                            setText(stat, 'Błąd: ' + (j && (j.error || j.message) ? (j.error || j.message) : 'unknown'));
                        }
                    })
                    .catch(function (err) {
                        reg.disabled = false; setText(stat, 'Błąd: ' + (err && err.message ? err.message : 'unknown'));
                    });
            });
        }
    }

    // ============ RAG ============
    function bindRag() {
        var btnStatus = byId('am-rag-status');
        var btnClear = byId('am-rag-clear');
        var btnReidx = byId('am-rag-reindex');
        var stat = byId('am-rag-status-text');

        var btnImport = byId('am-rag-manual-import');
        var stImport = byId('am-rag-manual-status');
        var inTitle = byId('am-rag-manual-title');
        var inUrl = byId('am-rag-manual-url');
        var inText = byId('am-rag-manual-text');
        var inChunk = byId('am-rag-chunk');
        var inOverlap = byId('am-rag-overlap');

        var ptPost = byId('am-rag-pt-post');
        var ptPage = byId('am-rag-pt-page');

        function refreshStatus() {
            if (!stat) return;
            setText(stat, 'Pobieram…');
            fetchJSON('rag/status')
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j && j.ok) { setText(stat, 'Total: ' + (j.total || 0) + ' | dim: ' + (j.dim || 0)); }
                    else { setText(stat, 'Błąd: ' + (j && (j.error || j.message) ? (j.error || j.message) : 'unknown')); }
                })
                .catch(function (err) {
                    setText(stat, 'Błąd: ' + (err && err.message ? err.message : 'unknown'));
                });
        }

        if (btnStatus) {
            btnStatus.addEventListener('click', function () { refreshStatus(); });
        }

        if (btnClear) {
            btnClear.addEventListener('click', function () {
                if (!confirm('Wyczyścić cały indeks RAG?')) return;
                setText(stat, 'Czyszczenie…');
                fetchJSON('rag/clear', { method: 'POST' })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        if (j && j.ok) { setText(stat, 'Indeks wyczyszczony'); }
                        else { setText(stat, 'Błąd: ' + (j && (j.error || j.message) ? (j.error || j.message) : 'unknown')); }
                    })
                    .catch(function (err) {
                        setText(stat, 'Błąd: ' + (err && err.message ? err.message : 'unknown'));
                    });
            });
        }

        // Reindeks (post/page) + kategorie
        if (btnReidx) {
            btnReidx.addEventListener('click', function () {
                var types = [];
                if (ptPost && ptPost.checked) types.push('post');
                if (ptPage && ptPage.checked) types.push('page');
                var chunk = parseInt(inChunk && inChunk.value || '1000', 10);
                var overlap = parseInt(inOverlap && inOverlap.value || '200', 10);

                // Kategorie
                var catInputs = document.querySelectorAll('#am-rag-cats-wrap input[name="am-rag-cat[]"]:checked');
                var categories = Array.prototype.slice.call(catInputs).map(function (el) {
                    return parseInt(el.value, 10);
                }).filter(function (v) { return !!v; });

                var includeChildrenEl = byId('am-rag-cats-children');
                var include_children = includeChildrenEl ? (includeChildrenEl.checked ? 1 : 0) : 1;

                if (types.length === 0) {
                    setText(stat, 'Zaznacz Posty i/lub Strony');
                    return;
                }
                busy(btnReidx, true);
                setText(stat, 'Reindeksuję… (to może potrwać)');

                fetchJSON('rag/reindex', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        post_types: types,
                        chunk: chunk,
                        overlap: overlap,
                        categories: categories,
                        include_children: include_children
                    })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        busy(btnReidx, false);
                        if (j && j.ok) {
                            setText(stat, 'OK: postów ' + (j.posts || 0) + ', chunków ' + (j.chunks || 0) + ', zapisano ' + (j.inserted || 0));
                            setTimeout(refreshStatus, 3000);
                        } else {
                            setText(stat, 'Błąd: ' + (j && (j.error || j.message) ? (j.error || j.message) : 'unknown'));
                        }
                    })
                    .catch(function (err) {
                        busy(btnReidx, false);
                        setText(stat, 'Błąd: ' + (err && err.message ? err.message : 'unknown'));
                    });
            });
        }

        // Plik -> textarea
        var fileInput = byId('am-rag-file');
        if (fileInput && inText) {
            fileInput.addEventListener('change', function () {
                var f = fileInput.files && fileInput.files[0];
                if (!f) return;
                var reader = new FileReader();
                reader.onload = function (ev) {
                    inText.value = ev.target.result || '';
                    setText(stImport, 'Załadowano: ' + f.name + ' (' + f.size + ' B)');
                    setTimeout(function () { setText(stImport, ''); }, 1800);
                };
                reader.readAsText(f);
            });
        }

        // Manual import
        if (btnImport) {
            btnImport.addEventListener('click', function () {
                var title = (inTitle && inTitle.value || '').trim();
                var url = (inUrl && inUrl.value || '').trim();
                var text = (inText && inText.value || '').trim();
                var chunk = parseInt(inChunk && inChunk.value || '1000', 10);
                var overlap = parseInt(inOverlap && inOverlap.value || '200', 10);

                if (!title || !text) {
                    setText(stImport, 'Podaj tytuł i wklej/załaduj tekst.');
                    return;
                }

                busy(btnImport, true);
                setText(stImport, 'Importuję…');

                fetchJSON('rag/manual', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ title: title, url: url, text: text, chunk: chunk, overlap: overlap })
                })
                    .then(function (r) { return r.json(); })
                    .then(function (j) {
                        busy(btnImport, false);
                        if (j && j.ok) {
                            setText(stImport, 'Zaindeksowano ' + (j.inserted || 0) + ' chunków (dim ' + (j.dim || 0) + ')');
                            setTimeout(refreshStatus, 3000);
                        } else {
                            setText(stImport, 'Błąd: ' + (j && (j.error || j.message) ? (j.error || j.message) : 'unknown'));
                        }
                    })
                    .catch(function (err) {
                        busy(btnImport, false);
                        setText(stImport, 'Błąd: ' + (err && err.message ? err.message : 'unknown'));
                    });
            });
        }
    }

    function init() {
        if (byId('am-refresh-models') || byId('am-test-openai') || byId('am-bearer-secret')) {
            bindModels();
            bindTest();
            bindBearer();
        }
        if (byId('am-rag-chunk') || byId('am-rag-manual-text')) {
            bindRag();
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();