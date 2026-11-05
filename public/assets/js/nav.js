// Navigation: Seitenleiste (ab 900 px, einklappbar) und Off-Canvas-Menü darunter.
const shell = document.querySelector('[data-shell]');

if (shell) {
    const sidebar = shell.querySelector('[data-sidebar]');
    const main = shell.querySelector('[data-shell-main]');
    const scrim = shell.querySelector('[data-nav-scrim]');
    const opener = shell.querySelector('[data-nav-open]');
    const collapse = shell.querySelector('[data-nav-collapse]');
    const wide = window.matchMedia('(min-width: 900px)');
    let open = false;

    const focusable = () =>
        [...sidebar.querySelectorAll('a[href], button:not([disabled])')].filter((n) => n.offsetParent !== null);

    function setOpen(next) {
        open = next && !wide.matches;
        shell.classList.toggle('is-open', open);
        scrim.hidden = !open;
        opener.setAttribute('aria-expanded', String(open));
        main.inert = open;
        document.body.classList.toggle('nav-locked', open);
        if (open) {
            (focusable()[0] ?? sidebar).focus();
        } else if (document.activeElement && sidebar.contains(document.activeElement)) {
            opener.focus();
        }
    }

    opener.addEventListener('click', () => setOpen(true));
    shell.querySelector('[data-nav-close]').addEventListener('click', () => setOpen(false));
    scrim.addEventListener('click', () => setOpen(false));

    document.addEventListener('keydown', (event) => {
        if (!open) {
            return;
        }
        if (event.key === 'Escape') {
            setOpen(false);
        } else if (event.key === 'Tab') {
            // Fokus bleibt im Menü, solange es offen ist
            const items = focusable();
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    // Wischen nach links schließt das Menü
    let startX = null;
    sidebar.addEventListener('touchstart', (event) => {
        startX = event.touches[0].clientX;
    }, { passive: true });
    sidebar.addEventListener('touchend', (event) => {
        if (startX !== null && event.changedTouches[0].clientX - startX < -60) {
            setOpen(false);
        }
        startX = null;
    });

    wide.addEventListener('change', () => setOpen(false));

    // Einklappen auf eine Symbolleiste, Zustand pro Gerät
    const applyCollapsed = (collapsed) => {
        if (collapsed) {
            document.documentElement.setAttribute('data-nav', 'collapsed');
        } else {
            document.documentElement.removeAttribute('data-nav');
        }
        collapse.setAttribute('aria-pressed', String(collapsed));
        collapse.textContent = collapsed ? '»' : 'Menü einklappen';
        collapse.setAttribute('aria-label', collapsed ? 'Menü ausklappen' : 'Menü einklappen');
        try {
            window.localStorage.setItem('ph-nav-collapsed', collapsed ? '1' : '0');
        } catch {
            /* Speicher gesperrt */
        }
    };
    applyCollapsed(document.documentElement.getAttribute('data-nav') === 'collapsed');
    collapse.addEventListener('click', () => applyCollapsed(document.documentElement.getAttribute('data-nav') !== 'collapsed'));
}
