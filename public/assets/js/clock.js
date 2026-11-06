// Kopfzeile: Datum, Uhrzeit und ISO-Kalenderwoche in Europe/Berlin, minütlich.
import { dayKeyOf, formatLongDay, formatTime, isoWeek, pad } from './time.js';

const bar = document.querySelector('[data-clockbar]');

if (bar) {
    const time = bar.querySelector('[data-now]');
    const date = bar.querySelector('[data-now-date]');
    const week = bar.querySelector('[data-now-week]');

    const render = () => {
        const now = Date.now();
        const key = dayKeyOf(now);
        const label = formatTime(now);
        time.textContent = label;
        time.dateTime = `${key}T${label}`;
        date.textContent = formatLongDay(key);
        week.textContent = `KW ${pad(isoWeek(key).week)}`;
    };

    render();
    const untilNextMinute = 60000 - (Date.now() % 60000);
    setTimeout(() => {
        render();
        setInterval(render, 60000);
    }, untilNextMinute);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            render();
        }
    });
}
