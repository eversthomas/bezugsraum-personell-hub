<?php
/** @var string $csrf */
/** @var \App\Items\Item|null $item */
/** @var int $duration */
/** @var list<int> $durations */
/** @var array<string, list<array<string, mixed>>> $byDay */
/** @var string $defaultTitle */
/** @var string $target */
/** @var array<string, string> $targets */
/** @var int|null $itemId */
/** @var array{type: string, message: string}|null $flash */
$weekdays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
?>
<main class="page page--narrow">
    <h1>Zeit reservieren</h1>
    <?php if ($item !== null) : ?>
    <p class="muted">Für: <strong><?= e($item->title) ?></strong></p>
    <?php endif; ?>
    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <nav class="chips" aria-label="Dauer">
        <?php foreach ($durations as $minutes) : ?>
        <a class="chip<?= $minutes === $duration ? ' is-active' : '' ?>" href="/reservieren?dauer=<?= e($minutes) ?><?= $itemId !== null ? '&amp;item=' . e($itemId) : '' ?>"<?= $minutes === $duration ? ' aria-current="true"' : '' ?>><?= e($minutes) ?> Min.</a>
        <?php endforeach; ?>
    </nav>

    <?php if ($byDay === []) : ?>
    <p class="card muted">In den nächsten 7 Tagen ist innerhalb der Arbeitszeiten kein freies Fenster dieser Länge. Kürzere Dauer wählen oder die Arbeitszeiten unter <a href="/einstellungen/planung">Planung</a> anpassen.</p>
    <?php endif; ?>

    <?php foreach ($byDay as $day => $slots) : ?>
    <section class="slotday">
        <?php $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day); ?>
        <h2><?= e($date === false ? $day : $weekdays[(int) $date->format('N') - 1] . ', ' . $date->format('d.m.')) ?></h2>
        <ul class="slots">
            <?php foreach ($slots as $slot) : ?>
            <li>
                <form method="post" action="/reservieren" class="slot">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="start" value="<?= e($slot['start']) ?>">
                    <input type="hidden" name="end" value="<?= e($slot['end']) ?>">
                    <?php if ($itemId !== null) : ?><input type="hidden" name="item" value="<?= e($itemId) ?>"><?php endif; ?>
                    <span class="slot__time tnum"><?= e($slot['label']) ?></span>
                    <label class="visually-hidden" for="title-<?= e($slot['start']) ?>">Titel im Kalender</label>
                    <input class="input slot__title" id="title-<?= e($slot['start']) ?>" name="title" type="text" maxlength="200" value="<?= e($defaultTitle) ?>" required>
                    <label class="visually-hidden" for="target-<?= e($slot['start']) ?>">Zielkalender</label>
                    <select class="input slot__target" id="target-<?= e($slot['start']) ?>" name="target">
                        <?php foreach ($targets as $key => $label) : ?>
                        <option value="<?= e($key) ?>"<?= $key === $target ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn--small" type="submit">Reservieren</button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endforeach; ?>

    <p class="small muted">Der Titel sollte neutral bleiben: Er steht später im Kalender des Zielkontos. Personal Hub selbst schreibt in keinen fremden Kalender.</p>
</main>
