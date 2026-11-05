// Segment-Schalter Auto / Hell / Dunkel. Zustand wird pro Gerät gemerkt.
const KEY = 'ph-theme';

function current() {
    try {
        const v = window.localStorage.getItem(KEY);
        return v === 'light' || v === 'dark' ? v : 'auto';
    } catch {
        return 'auto';
    }
}

function apply(theme) {
    if (theme === 'auto') {
        document.documentElement.removeAttribute('data-theme');
    } else {
        document.documentElement.setAttribute('data-theme', theme);
    }
    try {
        if (theme === 'auto') {
            window.localStorage.removeItem(KEY);
        } else {
            window.localStorage.setItem(KEY, theme);
        }
    } catch {
        /* Speicher gesperrt: gilt nur für diese Sitzung */
    }
}

export function initThemeSwitch(root = document) {
    const buttons = root.querySelectorAll('[data-theme-set]');
    const sync = () => {
        const active = current();
        buttons.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.themeSet === active)));
    };
    buttons.forEach((b) =>
        b.addEventListener('click', () => {
            apply(b.dataset.themeSet);
            sync();
        }),
    );
    sync();
}
