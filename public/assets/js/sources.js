import { api } from './api.js';

// Nachfrage vor dem Entfernen
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
    });
});

// „Verbindung testen“ im Formular für neue Quellen
const form = document.querySelector('[data-source-form]');
if (form) {
    const button = form.querySelector('[data-source-test]');
    const urlInput = form.querySelector('[data-source-url]');
    const out = form.querySelector('[data-source-test-result]');

    const show = (ok, text) => {
        out.hidden = false;
        out.className = `notice ${ok ? 'notice--ok' : 'notice--warn'}`;
        out.textContent = text;
    };

    button.addEventListener('click', async () => {
        if (!urlInput.value.trim()) {
            show(false, 'Bitte zuerst den ICS-Link eingeben.');
            return;
        }
        button.disabled = true;
        show(true, 'Verbindung wird getestet …');
        try {
            const result = await api('/calendar/test', { method: 'POST', body: { url: urlInput.value } });
            show(
                result.ok,
                result.ok
                    ? `Verbindung steht. ${result.eventCount} Termine im Zeitfenster gefunden.`
                    : `Test fehlgeschlagen: ${result.error}`,
            );
        } catch {
            show(false, 'Test nicht möglich. Bitte später erneut versuchen.');
        } finally {
            button.disabled = false;
        }
    });
}
