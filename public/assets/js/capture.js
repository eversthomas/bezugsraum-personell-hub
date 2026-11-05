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

async function sendText(item) {
    return fetch('/api/v1/items', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
        body: JSON.stringify({ text: item.text, origin: item.origin, client_uuid: item.uuid }),
    });
}

/** data:-URL zu Blob, ohne fetch (die Content-Security-Policy erlaubt kein connect-src data:). */
function dataUrlToBlob(dataUrl) {
    const [head, payload] = dataUrl.split(',');
    const mime = /data:([^;]+)/.exec(head)?.[1] ?? 'image/jpeg';
    const binary = atob(payload);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
        bytes[i] = binary.charCodeAt(i);
    }
    return new Blob([bytes], { type: mime });
}

async function sendPhoto(item) {
    const blob = dataUrlToBlob(item.photo);
    const form = new FormData();
    form.append('photo', blob, 'foto.jpg');
    form.append('text', item.text);
    form.append('client_uuid', item.uuid);
    return fetch('/api/v1/photos', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': csrf() },
        body: form,
    });
}

/** Verkleinert ein Foto im Browser (längste Seite 1600 px, JPEG) und berücksichtigt die Ausrichtung. */
async function shrinkPhoto(file) {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, 1600 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.82));
    if (!blob) {
        throw new Error('Foto konnte nicht umgewandelt werden');
    }
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(blob);
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
                response = item.photo ? await sendPhoto(item) : await sendText(item);
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

    const photoButton = form.querySelector('[data-capture-photo]');
    const fileInput = form.querySelector('[data-capture-file]');
    photoButton?.addEventListener('click', () => fileInput.click());
    fileInput?.addEventListener('change', async () => {
        const file = fileInput.files?.[0];
        fileInput.value = '';
        if (!file) {
            return;
        }
        say('Foto wird vorbereitet …');
        try {
            const photo = await shrinkPhoto(file);
            const entry = { uuid: uuid(), text: text.value.trim() || 'Foto', origin: 'photo', photo, at: Date.now() };
            const items = queue.read();
            items.push(entry);
            if (!queue.write(items)) {
                say('Das Foto konnte nicht vorgemerkt werden (Speicher voll).');
                return;
            }
            text.value = '';
            report(await flush());
        } catch {
            say('Das Foto konnte nicht verarbeitet werden.');
        }
    });

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
