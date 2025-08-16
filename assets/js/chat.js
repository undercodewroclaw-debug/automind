(function () {
    // Konfiguracja z PHP
    function cfg() { return window.AUTOMIND_CONFIG || {}; }

    // Prosty stan rozmowy (pamięć ostatnich wiadomości)
    function getState(root) {
        if (!root._amState) { root._amState = { history: [], max: 8 }; }
        return root._amState;
    }
    function pushHistory(root, role, content) {
        var st = getState(root);
        st.history.push({ role: role, content: String(content).slice(0, 2000) });
        if (st.history.length > st.max * 2) {
            st.history = st.history.slice(-st.max * 2);
        }
    }

    // HTML escape
    function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (m) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]); }); }

    // Dodaj wiadomość (user/bot) — z etykietą nad bąbelkiem
    function addMsg(root, role, text) {
        var c = cfg();
        var msgs = root.querySelector('.automind-messages');
        var labelText = (role === 'user') ? (c.userLabel || 'You') : (c.botName || 'Assistant');

        var wrap = document.createElement('div');
        wrap.className = 'am-msg ' + (role === 'user' ? 'user' : 'bot');

        // Etykieta z nazwą nad wiadomością
        var label = document.createElement('div');
        label.className = 'am-label';
        label.textContent = labelText;

        var bubble = document.createElement('div');
        bubble.className = 'am-bubble';
        bubble.textContent = text;

        // Kolejność: label -> bubble
        wrap.appendChild(label);
        wrap.appendChild(bubble);

        msgs.appendChild(wrap);
        msgs.scrollTop = msgs.scrollHeight;

        if (role === 'user' || role === 'assistant') {
            pushHistory(root, role === 'user' ? 'user' : 'assistant', text);
        }
        return bubble;
    }

    // Utwórz pusty bąbelek asystenta (dla streamu) — również z etykietą
    function createAssistantBubble(root) {
        var c = cfg();
        var msgs = root.querySelector('.automind-messages');

        var wrap = document.createElement('div');
        wrap.className = 'am-msg bot';

        var label = document.createElement('div');
        label.className = 'am-label';
        label.textContent = (c.botName || 'Assistant');

        var bubble = document.createElement('div');
        bubble.className = 'am-bubble';
        bubble.textContent = '';

        wrap.appendChild(label);
        wrap.appendChild(bubble);
        msgs.appendChild(wrap);
        msgs.scrollTop = msgs.scrollHeight;

        return bubble;
    }

    // Źródła pod odpowiedzią (render tylko gdy są)
    function addSources(root, sources) {
        if (!sources || !sources.length) return;
        var msgs = root.querySelector('.automind-messages');
        var cont = document.createElement('div');
        cont.className = 'am-sources';
        var html = '<span class="am-sources-label">Źródła: </span>' + sources.map(function (s) {
            var title = (s.title || 'Źródło');
            var url = s.url || '';
            if (url) {
                return '<a href="' + escapeHtml(url) + '" target="_blank" rel="nofollow noopener">' + escapeHtml(title) + '</a>';
            } else {
                return '<span>' + escapeHtml(title) + '</span>';
            }
        }).join(' · ');
        cont.innerHTML = html;
        msgs.appendChild(cont);
        msgs.scrollTop = msgs.scrollHeight;
    }

    // Wskaźnik "pisze..." — z etykietą zamiast avatara
    function setTyping(root, on) {
        var msgs = root.querySelector('.automind-messages');
        var exist = root.querySelector('.am-msg.typing');
        if (on) {
            if (exist) return;
            var c = cfg();
            var w = document.createElement('div'); w.className = 'am-msg bot typing';
            var label = document.createElement('div'); label.className = 'am-label'; label.textContent = (c.botName || 'Assistant');
            var b = document.createElement('div'); b.className = 'am-bubble'; b.textContent = 'Pisze…';
            w.appendChild(label); w.appendChild(b); msgs.appendChild(w); msgs.scrollTop = msgs.scrollHeight;
        } else {
            if (exist) exist.remove();
        }
    }

    // Tryb niedostępny (brak klucza/limit API/awaria sieci)
    function showUnavailable(root, reason) {
        var msg = 'Chwilowo niedostępny';
        if (reason) msg += ' (' + reason + ')';
        addMsg(root, 'bot', msg);
        var form = root.querySelector('.automind-form');
        var ta = form && form.querySelector('textarea[name="message"]');
        if (ta) { ta.disabled = true; ta.placeholder = 'Chat niedostępny'; }
        var btns = form && form.querySelectorAll('button');
        if (btns) btns.forEach(function (b) { b.disabled = true; });
    }

    // Powitanie
    function greet(root) {
        if (root.dataset.greeted === '1') return;
        var g = (cfg().greeting || '');
        if (g) { addMsg(root, 'bot', g); }
        root.dataset.greeted = '1';
    }

    // Wyczyść
    function clearChat(root) {
        var msgs = root.querySelector('.automind-messages');
        msgs.innerHTML = '';
        root._amState = { history: [], max: 8 };
        greet(root);
    }

    // Fallback REST /chat (po błędzie streamu lub watchdogu)
    function restOnce(root, text) {
        var c = cfg();
        var base = c.restUrl || '/wp-json/automind/v1/';
        var headers = { 'Content-Type': 'application/json' };
        if (c.nonce) headers['X-WP-Nonce'] = c.nonce;
        if (c.bearerEnabled && c.bearer) headers['Authorization'] = 'Bearer ' + c.bearer;

        var st = getState(root);
        setTyping(root, true);

        return fetch(base + 'chat', {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({
                message: text,
                botId: root.getAttribute('data-bot') || 'codi',
                history: st.history
            })
        })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Błąd JSON' }; }); })
            .then(function (j) {
                setTyping(root, false);
                if (j && j.ok && j.reply) {
                    addMsg(root, 'bot', j.reply);
                    // dopisz also do historii (lepszy kontekst przy kolejnych pytaniach)
                    pushHistory(root, 'assistant', j.reply);
                    if (j.sources && j.sources.length) addSources(root, j.sources);
                } else {
                    var reason = (j && j.status === 429) ? 'limit API' : (j && j.status ? ('HTTP ' + j.status) : 'błąd');
                    showUnavailable(root, reason);
                }
            })
            .catch(function () {
                setTyping(root, false);
                showUnavailable(root, 'network');
            });
    }

    // STREAMING (SSE) z watchdogiem timeout i fallbackiem
    function stream(root, text) {
        var c = cfg();
        var base = c.restUrl || '/wp-json/automind/v1/';
        var headers = { 'Content-Type': 'application/json' };
        if (c.nonce) headers['X-WP-Nonce'] = c.nonce;
        if (c.bearerEnabled && c.bearer) headers['Authorization'] = 'Bearer ' + c.bearer;

        var st = getState(root);
        setTyping(root, true);

        var controller = new AbortController();
        var gotFirst = false;
        var timer = setTimeout(function () {
            if (!gotFirst) controller.abort(); // watchdog – brak pierwszego tokena
        }, (cfg().sseTimeoutMs || 2500));

        return fetch(base + 'chat/stream', {
            method: 'POST',
            headers: headers,
            signal: controller.signal,
            body: JSON.stringify({
                message: text,
                botId: root.getAttribute('data-bot') || 'codi',
                history: st.history
            })
        }).then(async function (resp) {
            if (!resp.ok || !resp.body) throw new Error('no-stream');
            setTyping(root, false);

            var decoder = new TextDecoder('utf-8');
            var reader = resp.body.getReader();
            var buffer = '';
            var answer = '';
            var sources = [];
            var bubble = null;

            while (true) {
                const chunk = await reader.read();
                if (chunk.done) break;
                buffer += decoder.decode(chunk.value, { stream: true });

                let pos;
                while ((pos = buffer.indexOf('\n\n')) !== -1) {
                    const frame = buffer.slice(0, pos);
                    buffer = buffer.slice(pos + 2);

                    let ev = 'message', dataStr = '';
                    frame.split('\n').forEach(function (ln) {
                        if (ln.indexOf('event:') === 0) ev = ln.slice(6).trim();
                        else if (ln.indexOf('data:') === 0) dataStr += ln.slice(5).trim();
                    });
                    if (!dataStr) continue;
                    if (dataStr === '[DONE]') continue;

                    var data; try { data = JSON.parse(dataStr); } catch (e) { continue; }

                    if (ev === 'meta' && data.sources) {
                        sources = data.sources || [];
                    } else if (ev === 'delta' && typeof data.delta === 'string') {
                        if (!gotFirst) { gotFirst = true; clearTimeout(timer); }
                        if (!bubble) bubble = createAssistantBubble(root);
                        answer += data.delta;
                        bubble.textContent = answer;
                    } else if (ev === 'error') {
                        if (!bubble) bubble = createAssistantBubble(root);
                        bubble.textContent = 'Błąd: ' + (data.message || 'stream');
                    }
                }
            }

            if (!gotFirst) throw new Error('timeout'); // watchdog – fallback do REST
            if (!answer.trim()) throw new Error('empty');

            addSources(root, sources);
            pushHistory(root, 'assistant', answer);
        });
    }

    // Inicjalizacja widżetu
    function init() {
        document.querySelectorAll('.automind-widget').forEach(function (root) {
            var c = cfg();
            greet(root);

            // Brak klucza -> tryb offline
            if (!c.hasKey) { showUnavailable(root, 'brak klucza'); return; }

            var form = root.querySelector('.automind-form');
            var ta = root.querySelector('textarea[name="message"]');
            var clearBtn = root.querySelector('.automind-clear');

            // Fallback: jeśli był input, zamień na textarea
            if (!ta) {
                var inp = root.querySelector('input[name="message"]');
                if (inp) {
                    ta = document.createElement('textarea');
                    ta.name = 'message'; ta.rows = 2; ta.placeholder = inp.placeholder || 'Napisz wiadomość…';
                    inp.parentNode.replaceChild(ta, inp);
                }
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', function (e) { e.preventDefault(); clearChat(root); ta && ta.focus(); });
            }

            if (ta) {
                ta.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                    }
                });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var text = (ta && ta.value ? ta.value : '').trim();
                if (!text) return;

                addMsg(root, 'user', text);
                if (ta) ta.value = '';

                // Najpierw stream z watchdogiem; w razie czegokolwiek fallback do REST
                stream(root, text).catch(function () {
                    return restOnce(root, text);
                });
            });
        });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();