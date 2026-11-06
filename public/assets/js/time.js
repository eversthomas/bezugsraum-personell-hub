// Zeit-Hilfen: alle Anzeigen in Europe/Berlin, Rechnen mit Kalendertagen als "JJJJ-MM-TT".
export const TZ = 'Europe/Berlin';

const partsFormat = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
});

export const pad = (n) => String(n).padStart(2, '0');
export const dateKey = (y, m, d) => `${y}-${pad(m)}-${pad(d)}`;

export function parts(ms) {
    const out = {};
    for (const p of partsFormat.formatToParts(new Date(ms))) {
        if (p.type !== 'literal') {
            out[p.type] = parseInt(p.value, 10);
        }
    }
    return out;
}

export function dayKeyOf(ms) {
    const p = parts(ms);
    return dateKey(p.year, p.month, p.day);
}

const split = (key) => key.split('-').map(Number);

export function addDays(key, n) {
    const [y, m, d] = split(key);
    const t = new Date(Date.UTC(y, m - 1, d + n));
    return dateKey(t.getUTCFullYear(), t.getUTCMonth() + 1, t.getUTCDate());
}

/** 0 = Montag … 6 = Sonntag */
export function weekdayOf(key) {
    const [y, m, d] = split(key);
    return (new Date(Date.UTC(y, m - 1, d)).getUTCDay() + 6) % 7;
}

export const startOfWeek = (key) => addDays(key, -weekdayOf(key));

function utcDayNumber(key) {
    const [y, m, d] = split(key);
    return Math.round(Date.UTC(y, m - 1, d) / 86400000);
}

/** ISO-8601-Woche: Montag als Beginn, Woche 1 enthält den ersten Donnerstag. */
export function isoWeek(key) {
    const thursday = addDays(startOfWeek(key), 3);
    const isoYear = split(thursday)[0];
    const firstMonday = startOfWeek(dateKey(isoYear, 1, 4));
    const week = Math.round((utcDayNumber(startOfWeek(key)) - utcDayNumber(firstMonday)) / 7) + 1;

    return { year: isoYear, week };
}

/** Zeitstempel (ms) von 00:00 Uhr Berlin an diesem Kalendertag; berücksichtigt Sommer-/Winterzeit. */
export function midnightMs(key) {
    const [y, m, d] = split(key);
    const target = Date.UTC(y, m - 1, d);
    let guess = target;
    for (let i = 0; i < 3; i += 1) {
        const p = parts(guess);
        const local = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);
        guess -= local - target;
    }
    return guess;
}

export function minutesOfDay(ms) {
    const p = parts(ms);
    return p.hour * 60 + p.minute;
}

export const formatTime = (ms) => {
    const p = parts(ms);
    return `${pad(p.hour)}:${pad(p.minute)}`;
};

const longDay = new Intl.DateTimeFormat('de-DE', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long' });
const shortDay = new Intl.DateTimeFormat('de-DE', { timeZone: 'UTC', weekday: 'short', day: 'numeric' });
const monthYear = new Intl.DateTimeFormat('de-DE', { timeZone: 'UTC', month: 'long', year: 'numeric' });
const utcDate = (key) => {
    const [y, m, d] = split(key);
    return new Date(Date.UTC(y, m - 1, d));
};

export const formatLongDay = (key) => longDay.format(utcDate(key));
export const formatShortDay = (key) => shortDay.format(utcDate(key));
export const formatMonthYear = (key) => monthYear.format(utcDate(key));
export const dayOfMonth = (key) => split(key)[2];

export function formatCountdown(ms) {
    const minutes = Math.max(0, Math.round(ms / 60000));
    if (minutes < 1) {
        return 'jetzt';
    }
    if (minutes < 60) {
        return `in ${minutes} Min.`;
    }
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    if (h < 24) {
        return m === 0 ? `in ${h} Std.` : `in ${h} Std. ${m} Min.`;
    }
    const days = Math.floor(h / 24);
    return days === 1 ? 'morgen' : `in ${days} Tagen`;
}
