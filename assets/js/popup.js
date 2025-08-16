(function () {
    function qs(s, root) { return (root || document).querySelector(s); }

    function toggleOpen(root, on) {
        if (on === undefined) { root.classList.toggle('am-open'); }
        else { root.classList.toggle('am-open', !!on); }
        var panel = qs('.am-pop-panel', root);
        if (panel) { panel.setAttribute('aria-hidden', root.classList.contains('am-open') ? 'false' : 'true'); }
        // schowaj tip przy otwarciu
        if (root.classList.contains('am-open')) root.classList.remove('am-tip');
    }

    function reveal(root) {
        root.style.opacity = '1';
        root.classList.add('am-ready');
    }

    function init() {
        var root = document.getElementById('automind-pop');
        if (!root) return;

        var delay = parseInt(root.getAttribute('data-delay') || '0', 10);
        if (delay > 0) { setTimeout(function () { reveal(root); }, delay); }
        else { reveal(root); }

        var btn = qs('.am-pop-bubble', root);
        var cls = qs('.am-pop-close', root);

        if (btn) btn.addEventListener('click', function () { toggleOpen(root); });
        if (cls) cls.addEventListener('click', function () { toggleOpen(root, false); });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { toggleOpen(root, false); }
        });

        // TIP (dymek powitalny) – jeśli istnieje
        var tip = qs('.am-pop-tip', root);
        if (tip) {
            var closeBtn = qs('.am-tip-close', tip);
            var show = function () { root.classList.add('am-tip'); };
            var hide = function () { root.classList.remove('am-tip'); };

            // Pokaż po starcie (po opóźnieniu popupa), schowaj po ~5s
            setTimeout(function () {
                show();
                setTimeout(hide, 5000);
            }, delay + 400);

            if (closeBtn) closeBtn.addEventListener('click', function (e) { e.preventDefault(); hide(); });

            // Opcjonalnie pokaż na hover ikony, schowaj po zejściu
            var bubbleBtn = qs('.am-pop-bubble', root);
            if (bubbleBtn) {
                bubbleBtn.addEventListener('mouseenter', show);
                bubbleBtn.addEventListener('mouseleave', hide);
            }
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();