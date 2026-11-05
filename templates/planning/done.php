<?php
/** @var string $csrf */
/** @var \App\Calendar\PlannedBlock $block */
/** @var string $when */
/** @var string|null $link */
/** @var string $targetLabel */
/** @var array{type: string, message: string}|null $flash */
?>
<main class="page page--narrow">
    <h1>Termin vorbereitet</h1>
    <section class="card">
        <p><strong><?= e($block->title) ?></strong></p>
        <p class="tnum"><?= e($when) ?></p>
        <p class="muted">Zielkalender: <?= e($targetLabel) ?>. Der Block ist jetzt im Leitstand gestrichelt vorgemerkt und wird nach dem nächsten Abruf automatisch als bestätigt erkannt, sobald der Termin im Kalender steht.</p>
        <div class="actions">
            <?php if ($link !== null) : ?>
            <a class="btn" href="<?= e($link) ?>" target="_blank" rel="noopener noreferrer">In <?= e($targetLabel) ?> öffnen und speichern</a>
            <?php endif; ?>
            <a class="btn btn--ghost" href="/reservieren/<?= e($block->id) ?>/termin.ics" download>Als .ics-Datei laden</a>
        </div>
        <p class="small muted">Im geöffneten Formular nur noch auf „Speichern“ tippen. Klappt der Link nicht, die .ics-Datei im Zielkalender importieren.</p>
    </section>
    <form method="post" action="/reservieren/<?= e($block->id) ?>/verwerfen" data-confirm="Vormerkung verwerfen?">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--small btn--ghost btn--danger" type="submit">Vormerkung verwerfen</button>
    </form>
    <p><a href="/">Zum Leitstand</a></p>
</main>
<script type="module" src="/assets/js/confirm.js"></script>
