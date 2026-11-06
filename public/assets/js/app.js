import './nav.js';
import './clock.js';
import { initThemeSwitch } from './theme-switch.js';

initThemeSwitch();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            /* ohne Service Worker bleibt alles nutzbar, nur nicht installierbar */
        });
    });
}
