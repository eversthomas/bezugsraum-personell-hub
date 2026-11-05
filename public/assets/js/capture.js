// Erfassen mit Warteschlange: Einträge bekommen eine eigene Kennung (client_uuid), werden lokal vorgemerkt
// und gesendet, sobald das möglich ist. Der Server speichert dieselbe Kennung nur einmal.
const KEY = 'ph-queue';
const csrf = () => document.querySelector('meta[name="csrf"]')?.content ?? '';

const queue = {
    read() {
        try {
            const data = JSON.parse(window.localStorage.getItem(KEY) ?? '[]');
            return Array.isArray(data) ? data : [];
        } catch {
            return [];
        }
    },
    write(items) {
        try {
            window.localStorage.setItem(KEY, JSON.stringify(items));
            return true;
        } catch {
            return false;
        }
    },
};

const uuid = () =>
    typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
              (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16),
          );

const statusNodes = () => document.querySelectorAll('[data-capture-status]');
function say(text) {
    statusNodes().forEach((n) => {
        n.textContent = text;
    });
}

let flushing = false;

/** Sendet wartende Einträge der Reihe nach. Gibt die Anzahl noch wartender zurück. */
export async function flush() {
    if (flushing || !csrf()) {
        return queue.read().length;
    }
    flushing = true;
    try {
        let items = queue.read();
        while (items.length > 0) {
            const item = items[0];
            let response;
            try {
                response = await fetch('/api/v1/items', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
                    body: JSON.stringify({ text: item.text, origin: item.origin, client_uuid: item.uuid }),
                });
            } catch {
                break; // kein Netz: später erneut
            }
            if (response.ok || response.status === 422) {
                items = items.slice(1); // gespeichert, doppelt oder dauerhaft ungültig
                queue.write(items);
            } else {
                break; // Server- oder Anmeldeproblem: Eintrag bleibt erhalten
            }
        }
        return items.length;
    } finally {
        flushing = false;
    }
}

function report(waiting) {
    if (waiting === 0) {
        say('Gespeichert.');
    } else {
        say(
            waiting === 1
                ? '1 Eintrag wartet auf die Verbindung und wird automatisch gesendet.'
                : `${waiting} Einträge warten auf die Verbindung und werden automatisch gesendet.`,
        );
    }
}

document.querySelectorAll('form[data-capture]').forEach((form) => {
    const text = form.querySelector('[data-capture-text]');
    const origin = form.querySelector('[data-capture-origin]');
    const voice = form.querySelector('[data-capture-voice]');

    voice?.addEventListener('click', () => {
        origin.value = 'voice';
        text.focus();
        say('Mikrofon-Taste der Tastatur zum Diktieren verwenden.');
    });

    form.addEventListener('submit', async (event) => {
        const value = text.value.trim();
        if (!value) {
            return;
        }
        const entry = { uuid: uuid(), text: value, origin: origin.value || 'text', at: Date.now() };
        const items = queue.read();
        items.push(entry);
        if (!queue.write(items)) {
            return; // Speicher gesperrt: normales Formular sendet direkt
        }
        event.preventDefault();
        text.value = '';
        origin.value = 'text';
        text.focus();
        say('Wird gespeichert …');
        report(await flush());
    });
});

async function tick() {
    if (queue.read().length > 0) {
        const waiting = await flush();
        if (statusNodes().length) {
            report(waiting);
        }
    }
}

window.addEventListener('online', tick);
window.setInterval(tick, 60000);
tick();

const waiting = queue.read().length;
if (waiting > 0 && statusNodes().length) {
    report(waiting);
}
