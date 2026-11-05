// Push-Abo für dieses Gerät: aktivieren, testen, ausschalten.
import { api } from './api.js';

const root = document.querySelector('[data-push]');

if (root) {
    const status = root.querySelector('[data-push-status]');
    const enable = root.querySelector('[data-push-enable]');
    const test = root.querySelector('[data-push-test]');
    const disable = root.querySelector('[data-push-disable]');

    const toKey = (base64) => {
        const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(padded), (c) => c.charCodeAt(0));
    };

    const deviceLabel = () => {
        const ua = navigator.userAgent;
        const os = /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Windows/.test(ua) ? 'Windows' : /Mac/.test(ua) ? 'Mac' : 'Gerät';
        const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : 'Safari';
        return `${browser} auf ${os}`;
    };

    const show = (text, buttons = []) => {
        status.textContent = text;
        [enable, test, disable].forEach((b) => {
            b.hidden = !buttons.includes(b);
        });
    };

    async function refresh() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            show('Dieser Browser unterstützt keine Push-Nachrichten. Auf dem iPhone muss die App zuerst zum Home-Bildschirm hinzugefügt werden.');
            return;
        }
        if (Notification.permission === 'denied') {
            show('Benachrichtigungen sind für diese Seite blockiert. In den Browser-Einstellungen wieder erlauben.');
            return;
        }
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
            show('Dieses Gerät ist aktiv.', [test, disable]);
        } else {
            show('Dieses Gerät ist nicht aktiv.', [enable]);
        }
    }

    enable.addEventListener('click', async () => {
        enable.disabled = true;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                await refresh();
                return;
            }
            const { publicKey } = await api('/push/key');
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: toKey(publicKey),
            });
            const json = subscription.toJSON();
            await api('/push/subscribe', {
                method: 'POST',
                body: { endpoint: json.endpoint, keys: json.keys, label: deviceLabel() },
            });
            window.location.reload();
        } catch {
            show('Aktivieren hat nicht geklappt. Bitte erneut versuchen.', [enable]);
        } finally {
            enable.disabled = false;
        }
    });

    test.addEventListener('click', async () => {
        test.disabled = true;
        try {
            const result = await api('/push/test', { method: 'POST', body: {} });
            show(result.delivered > 0 ? 'Testnachricht gesendet.' : 'Die Testnachricht konnte nicht zugestellt werden.', [test, disable]);
        } catch {
            show('Senden nicht möglich.', [test, disable]);
        } finally {
            test.disabled = false;
        }
    });

    disable.addEventListener('click', async () => {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        if (subscription) {
            await api('/push/unsubscribe', { method: 'POST', body: { endpoint: subscription.endpoint } }).catch(() => {});
            await subscription.unsubscribe();
        }
        window.location.reload();
    });

    refresh();
}
