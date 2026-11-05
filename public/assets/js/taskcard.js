// Aufgaben-Karte im Leitstand: überfällige und heutige Aufgaben, direkt abhakbar.
import { api } from './api.js';

const card = document.querySelector('[data-taskcard]');

const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) {
        node.className = className;
    }
    if (text !== undefined) {
        node.textContent = text;
    }
    return node;
};

async function load() {
    let data;
    try {
        data = await api('/tasks/due');
    } catch {
        return; // Karte bleibt im alten Zustand
    }
    card.replaceChildren();
    if (data.items.length === 0) {
        card.hidden = true;
        return;
    }
    card.hidden = false;

    const parts = [];
    if (data.overdue) {
        parts.push(`${data.overdue} überfällig`);
    }
    if (data.today) {
        parts.push(`${data.today} heute`);
    }
    card.append(el('h2', undefined, `Aufgaben · ${parts.join(', ')}`));

    const list = el('ul', 'tasks');
    for (const task of data.items) {
        const row = el('li', 'task');
        const check = el('button', 'task__check');
        check.type = 'button';
        check.setAttribute('aria-label', `Erledigt: ${task.title}`);
        check.addEventListener('click', async () => {
            check.disabled = true;
            try {
                await api(`/tasks/${task.id}/complete`, { method: 'POST', body: {} });
                await load();
            } catch {
                check.disabled = false;
            }
        });
        const link = el('a', 'task__title', task.title);
        link.href = `/aufgaben/${task.id}`;
        row.append(check, link);
        list.append(row);
    }
    card.append(list);

    const more = el('p', 'taskcard__more small');
    const all = el('a', undefined, 'Alle Aufgaben');
    all.href = '/aufgaben';
    more.append(all);
    card.append(more);
}

if (card) {
    load();
    window.setInterval(load, 5 * 60 * 1000);
}
