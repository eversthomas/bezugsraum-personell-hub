<?php
/** @var string $csrf */
?>
<main class="leitstand" data-leitstand>
    <section class="hero" aria-label="Uhrzeit und nächster Termin">
        <div class="hero__time">
            <span class="hero__clock tnum" data-clock>--:--</span>
            <span class="hero__date muted" data-date></span>
        </div>
        <div class="next" data-next></div>
    </section>

    <div class="toolbar">
        <div class="segment" role="group" aria-label="Ansicht" data-views></div>
        <div class="toolbar__nav">
            <button type="button" class="btn btn--small btn--ghost" data-prev aria-label="Zurück">‹</button>
            <button type="button" class="btn btn--small btn--ghost" data-today>Heute</button>
            <button type="button" class="btn btn--small btn--ghost" data-next-range aria-label="Weiter">›</button>
        </div>
        <h1 class="toolbar__title" data-title aria-live="polite"></h1>
    </div>

    <div class="chips" role="group" aria-label="Quellen ein- oder ausblenden" data-sources></div>
    <div class="notice notice--warn" role="status" data-status hidden></div>

    <div class="stage">
        <div class="calendar" data-calendar></div>
        <aside data-detail hidden></aside>
    </div>
</main>
<script type="module" src="/assets/js/leitstand.js"></script>
