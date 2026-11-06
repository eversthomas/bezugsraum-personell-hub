import { api } from './api.js';
import {
    addDays,
    dayKeyOf,
    dayOfMonth,
    formatCountdown,
    formatLongDay,
    formatMonthYear,
    formatShortDay,
    formatTime,
    midnightMs,
    minutesOfDay,
    startOfWeek,
    weekdayOf,
} from './time.js';

const root = document.querySelector('[data-leitstand]');
const VIEWS = { day: 'Tag', week: 'Woche', w2: '2 Wochen', w4: '4 Wochen', agenda: 'Agenda' };
const DAYS = { day: 1, week: 7, w2: 14, w4: 28, agenda: 14 };
const HOUR_PX = 56;
const REFRESH_MS = 5 * 60 * 1000;

const narrow = window.matchMedia('(max-width: 699px)');

const store = {
    get(key, fallback) {
        try {
            const raw = window.localStorage.getItem(`ph-${key}`);
            return raw === null ? fallback : JSON.parse(raw);
        } catch {
            return fallback;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(`ph-${key}`, JSON.stringify(value));
        } catch {
            /* Speicher gesperrt: gilt nur für diese Sitzung */
        }
    },
};

function initialView() {
    const stored = store.get('view', 'day');
    if (typeof stored !== 'string' || !Object.hasOwn(VIEWS, stored)) {
        return 'day';
    }
    // Auf dem Handy bleiben die Wochenraster aus, Tag und Agenda nicht.
    if (narrow.matches && stored !== 'day' && stored !== 'agenda') {
        return 'day';
    }
    return stored;
}

const state = {
    view: initialView(),
    anchor: dayKeyOf(Date.now()),
    hidden: new Set(store.get('hidden-sources', [])),
    sources: [],
    events: [],
    upcoming: [],
    planned: [],
    selected: null,
    returnKey: '',
    returnButton: null,
    loadedAt: null,
    failed: false,
};

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

// ---------- Daten ----------

function normalize(raw) {
    if (raw.allDay) {
        return { ...raw, startKey: raw.start, endKey: raw.end };
    }
    return { ...raw, startMs: Date.parse(raw.start), endMs: Date.parse(raw.end) };
}

function range() {
    const days = DAYS[state.view];
    const from = state.view === 'day' || state.view === 'agenda' ? state.anchor : startOfWeek(state.anchor);
    return { from, to: addDays(from, days) };
}

async function load() {
    const { from, to } = range();
    const today = dayKeyOf(Date.now());
    try {
        const [main, soon] = await Promise.all([
            api(`/calendar/events?from=${from}&to=${to}`),
            api(`/calendar/events?from=${today}&to=${addDays(today, 8)}`),
        ]);
        state.sources = main.sources;
        state.events = main.events.map(normalize);
        state.planned = (main.planned ?? []).map((p) => ({ ...normalize({ ...p, allDay: false, source: 0, location: '' }), planned: true }));
        state.upcoming = soon.events.map(normalize);
        state.loadedAt = Date.now();
        state.failed = false;
    } catch {
        state.failed = true;
    }
    render();
}

const sourceById = (id) => state.sources.find((s) => s.id === id);
const visible = (events) => events.filter((e) => !state.hidden.has(e.source));

// ---------- Kopf: Uhr, nächster Termin ----------

function renderHeader() {
    const now = Date.now();
    root.querySelector('[data-clock]').textContent = formatTime(now);
    root.querySelector('[data-date]').textContent = formatLongDay(dayKeyOf(now));

    const timed = visible(state.upcoming).filter((e) => !e.allDay);
    const running = timed.find((e) => e.startMs <= now && e.endMs > now);
    const next = timed.find((e) => e.startMs > now);
    const box = root.querySelector('[data-next]');
    box.replaceChildren();
    const target = running ?? next;
    if (!target) {
        box.append(el('span', 'muted', 'Kein weiterer Termin in den nächsten 7 Tagen.'));
        return;
    }
    const src = sourceById(target.source);
    box.className = `next ${src ? src.class : 'src-neutral'}`;
    box.append(
        el('span', 'next__label', running ? 'Läuft gerade' : 'Nächster Termin'),
        el('strong', 'next__title', target.title),
        el(
            'span',
            'next__when tnum',
            running
                ? `bis ${formatTime(running.endMs)}`
                : `${formatCountdown(target.startMs - now)} · ${formatTime(target.startMs)}`,
        ),
    );
}

// ---------- Steuerung ----------

function renderControls() {
    const seg = root.querySelector('[data-views]');
    seg.replaceChildren();
    for (const [key, label] of Object.entries(VIEWS)) {
        const b = el('button', undefined, label);
        b.type = 'button';
        b.dataset.view = key;
        b.setAttribute('aria-pressed', String(state.view === key));
        b.addEventListener('click', () => {
            state.view = key;
            store.set('view', key);
            load();
        });
        seg.append(b);
    }

    const { from, to } = range();
    const title = root.querySelector('[data-title]');
    const endLabel = formatShortDay(addDays(to, -1));
    if (state.view === 'day') {
        title.textContent = formatLongDay(from);
    } else if (state.view === 'agenda') {
        title.textContent = `${formatLongDay(from)} – ${formatLongDay(addDays(to, -1))}`;
    } else if (state.view === 'week') {
        title.textContent = `${formatShortDay(from)} – ${endLabel}`;
    } else {
        title.textContent = `${formatMonthYear(from)} · ${formatShortDay(from)} – ${endLabel}`;
    }

    const chips = root.querySelector('[data-sources]');
    chips.replaceChildren();
    for (const s of state.sources.filter((x) => x.active)) {
        const b = el('button', `chip ${s.class}`);
        b.type = 'button';
        b.setAttribute('aria-pressed', String(!state.hidden.has(s.id)));
        b.append(el('span', 'chip__dot'), el('span', undefined, s.name));
        if (s.error) {
            const warn = el('span', 'chip__warn', '!');
            warn.title = 'Abruf fehlgeschlagen';
            b.append(warn);
        }
        b.addEventListener('click', () => {
            state.hidden.has(s.id) ? state.hidden.delete(s.id) : state.hidden.add(s.id);
            store.set('hidden-sources', [...state.hidden]);
            render();
        });
        chips.append(b);
    }

    const status = root.querySelector('[data-status]');
    status.replaceChildren();
    status.hidden = true;
    const broken = state.sources.filter((s) => s.active && s.error);
    const messages = [];
    if (state.failed) {
        messages.push('Termine konnten nicht geladen werden. Zuletzt angezeigter Stand bleibt sichtbar.');
    }
    for (const s of broken) {
        messages.push(`${s.name}: Abruf fehlgeschlagen. ${s.error}`);
    }
    if (messages.length) {
        status.hidden = false;
        for (const m of messages) {
            status.append(el('p', undefined, m));
        }
    }
}

// ---------- Zeitraster (Tag / Woche) ----------

function layoutLanes(segments) {
    const sorted = [...segments].sort((a, b) => a.start - b.start || b.end - a.end);
    let cluster = [];
    let clusterEnd = -1;
    const flush = () => {
        const lanes = [];
        for (const seg of cluster) {
            let lane = lanes.findIndex((end) => end <= seg.start);
            if (lane === -1) {
                lane = lanes.length;
                lanes.push(0);
            }
            lanes[lane] = seg.end;
            seg.lane = lane;
        }
        cluster.forEach((seg) => {
            seg.lanes = lanes.length;
        });
        cluster = [];
    };
    for (const seg of sorted) {
        if (cluster.length && seg.start >= clusterEnd) {
            flush();
            clusterEnd = -1;
        }
        cluster.push(seg);
        clusterEnd = Math.max(clusterEnd, seg.end);
    }
    if (cluster.length) {
        flush();
    }
    return sorted;
}

function eventKey(e) {
    if (e.planned) {
        return `p:${e.id}`;
    }
    return `${e.source}|${e.allDay ? e.startKey : e.startMs}|${e.title}`;
}

function eventButton(e, className) {
    const src = sourceById(e.source);
    const b = el('button', `ev ${src ? src.class : 'src-neutral'} ${e.planned ? 'ev--planned' : ''} ${className}`);
    b.type = 'button';
    b.dataset.eventKey = eventKey(e);
    if (state.selected && eventKey(state.selected) === eventKey(e)) {
        b.classList.add('is-selected');
    }
    b.addEventListener('click', () => select(e, b));
    return b;
}

function renderGrid(container) {
    const { from } = range();
    const dayCount = DAYS[state.view];
    const keys = Array.from({ length: dayCount }, (_, i) => addDays(from, i));
    const events = visible(state.events);
    const timed = [...events.filter((e) => !e.allDay), ...state.planned];

    // sichtbarer Stundenbereich: 7–19 Uhr als Standard, erweitert bei Terminen außerhalb
    let startHour = 7;
    let endHour = 19;
    const spans = keys.map((key) => {
        const dayStart = midnightMs(key);
        const dayEnd = midnightMs(addDays(key, 1));
        return timed
            .filter((e) => e.startMs < dayEnd && e.endMs > dayStart)
            .map((e) => ({
                e,
                start: Math.max(e.startMs, dayStart),
                end: Math.min(Math.max(e.endMs, e.startMs + 15 * 60000), dayEnd),
                dayStart,
            }));
    });
    for (const list of spans) {
        for (const s of list) {
            startHour = Math.min(startHour, Math.floor(minutesOfDay(s.start) / 60));
            const endMin = s.end >= s.dayStart + 24 * 3600000 - 60000 ? 24 * 60 : minutesOfDay(s.end);
            endHour = Math.max(endHour, Math.ceil(endMin / 60));
        }
    }
    endHour = Math.min(24, endHour);
    const minutes = (endHour - startHour) * 60;
    const today = dayKeyOf(Date.now());

    const grid = el('div', `cal cal--${state.view}`);
    grid.style.setProperty('--days', String(dayCount));

    if (state.view !== 'day') {
        grid.append(el('div', 'cal__corner'));
        keys.forEach((key) => {
            const head = el('div', `cal__dayhead${key === today ? ' is-today' : ''}${weekdayOf(key) >= 5 ? ' is-weekend' : ''}`);
            head.textContent = formatShortDay(key);
            grid.append(head);
        });
    }

    // Ganztägige Termine
    grid.append(el('div', 'cal__corner cal__corner--allday', 'ganztägig'));
    keys.forEach((key) => {
        const cell = el('div', 'cal__allday');
        for (const e of events.filter((x) => x.allDay && x.startKey <= key && x.endKey > key)) {
            const b = eventButton(e, 'ev--allday');
            b.append(el('span', 'ev__title', e.title));
            cell.append(b);
        }
        grid.append(cell);
    });

    // Stundenachse und Spalten
    const body = el('div', 'cal__body');
    body.style.height = `${(minutes / 60) * HOUR_PX}px`;
    const hours = el('div', 'cal__hours');
    for (let h = startHour; h < endHour; h += 1) {
        const label = el('span', 'cal__hour tnum', `${String(h).padStart(2, '0')}:00`);
        label.style.top = `${(h - startHour) * HOUR_PX}px`;
        hours.append(label);
    }
    body.append(hours);

    keys.forEach((key, index) => {
        const col = el('div', `cal__col${key === today ? ' is-today' : ''}${weekdayOf(key) >= 5 ? ' is-weekend' : ''}`);
        for (let h = 1; h < endHour - startHour; h += 1) {
            const line = el('div', 'cal__line');
            line.style.top = `${h * HOUR_PX}px`;
            col.append(line);
        }
        const segs = layoutLanes(
            spans[index].map((s) => ({
                ...s,
                start: (s.start - s.dayStart) / 60000,
                end: (s.end - s.dayStart) / 60000,
            })),
        );
        for (const s of segs) {
            const b = eventButton(s.e, '');
            b.style.top = `${((s.start - startHour * 60) / 60) * HOUR_PX}px`;
            b.style.height = `${Math.max(((s.end - s.start) / 60) * HOUR_PX - 2, 20)}px`;
            b.style.left = `${(s.lane / s.lanes) * 100}%`;
            b.style.width = `calc(${100 / s.lanes}% - 2px)`;
            b.append(el('span', 'ev__time tnum', formatTime(s.e.startMs)), el('span', 'ev__title', s.e.planned ? `${s.e.title} (vorgemerkt)` : s.e.title));
            col.append(b);
        }
        if (key === today) {
            const nowMin = minutesOfDay(Date.now());
            if (nowMin >= startHour * 60 && nowMin <= endHour * 60) {
                const line = el('div', 'cal__now');
                line.style.top = `${((nowMin - startHour * 60) / 60) * HOUR_PX}px`;
                line.dataset.nowLine = String(startHour * 60);
                col.append(line);
            }
        }
        body.append(col);
    });

    grid.append(body);
    container.append(grid);
}

// ---------- Agenda ----------

function renderAgenda(container) {
    const today = dayKeyOf(Date.now());
    const tomorrow = addDays(today, 1);
    const { from } = range();
    const items = [...visible(state.events), ...state.planned];
    const wrap = el('div', 'agenda');
    let shown = 0;

    for (let i = 0; i < DAYS.agenda; i += 1) {
        const key = addDays(from, i);
        const dayStart = midnightMs(key);
        const dayEnd = midnightMs(addDays(key, 1));
        const dayItems = items
            .filter((event) => (event.allDay
                ? event.startKey <= key && event.endKey > key
                : event.startMs < dayEnd && event.endMs > dayStart))
            .sort((a, b) => (a.allDay === b.allDay ? (a.startMs ?? 0) - (b.startMs ?? 0) : (a.allDay ? -1 : 1)));
        if (dayItems.length === 0) {
            continue;
        }
        shown += dayItems.length;
        const day = el('section', 'agenda__day');
        const heading = key === today ? 'Heute' : key === tomorrow ? 'Morgen' : formatLongDay(key);
        day.append(el('h2', 'agenda__heading', heading));
        const list = el('ul', 'agenda__list');
        for (const event of dayItems) {
            const row = eventButton(event, 'ev--agenda');
            const when = event.allDay ? 'ganztägig' : `${formatTime(event.startMs)}–${formatTime(event.endMs)}`;
            row.append(el('span', 'ev__time tnum', when));
            row.append(el('span', 'ev__title', event.planned ? `${event.title} (vorgemerkt)` : event.title));
            const source = sourceById(event.source);
            if (source) {
                row.append(el('span', 'ev__source', source.name));
            }
            const item = el('li');
            item.append(row);
            list.append(item);
        }
        day.append(list);
        wrap.append(day);
    }

    if (shown === 0) {
        wrap.append(el('p', 'muted', 'Keine Termine in diesem Zeitraum.'));
    }
    container.append(wrap);
}

// ---------- Mehrwochen-Raster ----------

function renderMonth(container) {
    const { from } = range();
    const dayCount = DAYS[state.view];
    const today = dayKeyOf(Date.now());
    const events = visible(state.events);
    const wrap = el('div', 'mgrid');

    ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'].forEach((d) => wrap.append(el('div', 'mgrid__head', d)));
    for (let i = 0; i < dayCount; i += 1) {
        const key = addDays(from, i);
        const cell = el('div', `mday${key === today ? ' is-today' : ''}${weekdayOf(key) >= 5 ? ' is-weekend' : ''}${key < today ? ' is-past' : ''}`);
        cell.append(el('span', 'mday__num tnum', String(dayOfMonth(key))));
        const dayStart = midnightMs(key);
        const dayEnd = midnightMs(addDays(key, 1));
        const items = events
            .filter((e) => (e.allDay ? e.startKey <= key && e.endKey > key : e.startMs < dayEnd && e.endMs > dayStart))
            .sort((a, b) => (a.allDay ? -1 : 0) - (b.allDay ? -1 : 0) || (a.startMs ?? 0) - (b.startMs ?? 0));
        for (const e of items.slice(0, 4)) {
            const b = eventButton(e, 'ev--line');
            b.append(el('span', 'ev__dot'));
            b.append(el('span', 'ev__title', e.allDay ? e.title : `${formatTime(e.startMs)} ${e.title}`));
            cell.append(b);
        }
        if (items.length > 4) {
            cell.append(el('span', 'mday__more small muted', `+${items.length - 4} weitere`));
        }
        wrap.append(cell);
    }
    container.append(wrap);
}

// ---------- Details ----------

const sheetQuery = window.matchMedia('(max-width: 1099px)');

function markSelected() {
    const key = state.selected ? eventKey(state.selected) : '';
    root.querySelectorAll('.ev').forEach((node) => {
        node.classList.toggle('is-selected', key !== '' && node.dataset.eventKey === key);
    });
}

function focusEvent() {
    if (state.returnButton instanceof HTMLElement && state.returnButton.isConnected) {
        state.returnButton.focus();
        return;
    }
    if (!state.returnKey) {
        return;
    }
    root.querySelector(`.ev[data-event-key="${CSS.escape(state.returnKey)}"]`)?.focus();
}

function select(event, button) {
    if (event) {
        state.selected = event;
        state.returnKey = eventKey(event);
        state.returnButton = button ?? null;
        renderDetail(true);
        markSelected();
        return;
    }
    state.selected = null;
    renderDetail(false);
    markSelected();
    focusEvent();
    state.returnButton = null;
}

function renderDetail(moveFocus) {
    const box = root.querySelector('[data-detail]');
    const scrim = root.querySelector('[data-detail-scrim]');
    const event = state.selected;
    const keepFocus = box.contains(document.activeElement);
    if (!event) {
        box.hidden = true;
        box.replaceChildren();
        scrim.hidden = true;
        document.body.classList.remove('detail-open');
        return;
    }
    const src = sourceById(event.source);
    const when = event.allDay
        ? event.endKey > addDays(event.startKey, 1)
            ? `${formatLongDay(event.startKey)} bis ${formatLongDay(addDays(event.endKey, -1))}`
            : formatLongDay(event.startKey)
        : `${formatLongDay(dayKeyOf(event.startMs))}, ${formatTime(event.startMs)}–${formatTime(event.endMs)} Uhr`;
    const title = el('h2', undefined, event.title);
    title.id = 'detail-title';
    const close = el('button', 'btn btn--ghost detail__close', 'Schließen');
    close.type = 'button';
    close.addEventListener('click', () => select(null));
    const head = el('div', 'detail__head');
    head.append(title, close);
    box.replaceChildren(
        head,
        el('p', 'tnum', when),
        el('p', 'muted', [src?.name, event.location].filter(Boolean).join(' · ')),
    );
    box.className = `detail card ${src ? src.class : 'src-neutral'}`;
    box.tabIndex = -1;
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', sheetQuery.matches ? 'true' : 'false');
    box.setAttribute('aria-labelledby', 'detail-title');
    box.hidden = false;
    scrim.hidden = !sheetQuery.matches;
    document.body.classList.toggle('detail-open', sheetQuery.matches);
    if (moveFocus || keepFocus) {
        box.focus({ preventScroll: true });
    }
}

// ---------- Gesamt ----------

function render() {
    renderHeader();
    renderControls();
    const container = root.querySelector('[data-calendar]');
    container.replaceChildren();
    if (state.view === 'agenda') {
        renderAgenda(container);
    } else if (state.view === 'day' || state.view === 'week') {
        renderGrid(container);
    } else {
        renderMonth(container);
    }
    renderDetail();
    const scroller = root.querySelector('.cal__body');
    if (scroller && !state.scrolled) {
        state.scrolled = true;
    }
}

// Der Anker bleibt der gewählte Tag. Der Montag gilt nur für die Anzeige der Wochenraster.
function shift(direction) {
    state.anchor = addDays(state.anchor, direction * DAYS[state.view]);
    load();
}

root.querySelector('[data-detail-scrim]').addEventListener('click', () => select(null));
sheetQuery.addEventListener('change', () => {
    if (state.selected) {
        renderDetail(false);
    }
});
document.addEventListener('keydown', (event) => {
    if (!state.selected || document.querySelector('[data-shell]')?.classList.contains('is-open')) {
        return;
    }
    const box = root.querySelector('[data-detail]');
    if (event.key === 'Escape') {
        event.preventDefault();
        select(null);
        return;
    }
    if (event.key !== 'Tab' || !sheetQuery.matches) {
        return;
    }
    const items = [...box.querySelectorAll('button, a[href], input, select, textarea')].filter((node) => !node.disabled);
    if (items.length === 0) {
        event.preventDefault();
        box.focus();
        return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    const active = document.activeElement;
    if (event.shiftKey && (active === first || active === box)) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
    }
});

root.querySelector('[data-prev]').addEventListener('click', () => shift(-1));
root.querySelector('[data-next-range]').addEventListener('click', () => shift(1));
root.querySelector('[data-today]').addEventListener('click', () => {
    state.anchor = dayKeyOf(Date.now());
    load();
});

// Uhr und Jetzt-Linie laufen sekundengenau, Daten alle 5 Minuten neu.
setInterval(() => {
    renderHeader();
    const line = root.querySelector('[data-now-line]');
    if (line) {
        line.style.top = `${((minutesOfDay(Date.now()) - Number(line.dataset.nowLine)) / 60) * HOUR_PX}px`;
    }
}, 15000);
setInterval(load, REFRESH_MS);
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && Date.now() - (state.loadedAt ?? 0) > 60000) {
        load();
    }
});

load();
