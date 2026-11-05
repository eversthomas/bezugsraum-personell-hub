// Setzt das gemerkte Farbschema vor dem ersten Zeichnen (kein Aufblitzen).
(function () {
    var theme = 'auto';
    try {
        var stored = window.localStorage.getItem('ph-theme');
        if (stored === 'light' || stored === 'dark') {
            theme = stored;
        }
    } catch (e) {
        /* Speicher gesperrt: Auto bleibt */
    }
    try {
        if (window.localStorage.getItem('ph-nav-collapsed') === '1') {
            document.documentElement.setAttribute('data-nav', 'collapsed');
        }
    } catch (e) {
        /* Speicher gesperrt */
    }
    if (theme !== 'auto') {
        document.documentElement.setAttribute('data-theme', theme);
    }
})();
