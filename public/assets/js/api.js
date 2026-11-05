// fetch-Hilfe für /api/v1: sendet das CSRF-Token mit und vereinheitlicht Fehler.
const csrf = () => document.querySelector('meta[name="csrf"]')?.content ?? '';

export class ApiError extends Error {
    constructor(status, message) {
        super(message);
        this.status = status;
    }
}

export async function api(path, { method = 'GET', body } = {}) {
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') {
        headers['X-CSRF-Token'] = csrf();
    }
    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }
    const response = await fetch(`/api/v1${path}`, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!response.ok) {
        throw new ApiError(response.status, `Anfrage fehlgeschlagen (${response.status})`);
    }

    return response.status === 204 ? null : response.json();
}
