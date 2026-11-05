<?php
/** @var string $csrf */
/** @var array<string, list<\App\Items\Item>> $groups */
/** @var list<\App\Items\Item> $done */
/** @var list<array{key: string, label: string, color: string}> $contexts */
/** @var string|null $filter */
/** @var array<string, string> $recurrences */
/** @var array{type: string, message: string}|null $flash */

$labels = [
    'overdue' => 'Überfällig',
    'today' => 'Heute',
    'soon' => 'Demnächst (7 Tage)',
    'later' => 'Später',
    'undated' => 'Ohne Datum',
];
$contextLabel = [];
foreach ($contexts as $context) {
    $contextLabel[$context['key']] = $context['label'];
}
$dueText = static function (?string $due): string {
    if ($due === null) {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $due);

    return $date === false ? '' : $date->format('d.m.Y');
};
$total = array_sum(array_map('count', $groups));
?>
<main class="page">
    <header class="page__head">
        <h1>Aufgaben</h1>
        <p class="muted"><?= e($total) ?> offen</p>
    </header>

    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="/aufgaben" class="card task-new">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label class="visually-hidden" for="task-title">Neue Aufgabe</label>
        <input class="input task-new__title" id="task-title" name="title" type="text" maxlength="500" placeholder="Neue Aufgabe …" required>
        <label class="visually-hidden" for="task-context">Kontext</label>
        <select class="input" id="task-context" name="context">
            <option value="">Kontext</option>
            <?php foreach ($contexts as $context) : ?>
            <option value="<?= e($context['key']) ?>"><?= e($context['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="visually-hidden" for="task-due">Fällig am</label>
        <input class="input" id="task-due" name="due" type="date">
        <label class="visually-hidden" for="task-rec">Wiederholung</label>
        <select class="input" id="task-rec" name="recurrence">
            <option value="">Einmalig</option>
            <?php foreach ($recurrences as $value => $label) : ?>
            <option value="<?= e($value) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Anlegen</button>
    </form>

    <nav class="chips" aria-label="Nach Kontext filtern">
        <a class="chip<?= $filter === null ? ' is-active' : '' ?>" href="/aufgaben"<?= $filter === null ? ' aria-current="true"' : '' ?>>Alle</a>
        <?php foreach ($contexts as $context) : ?>
        <a class="chip<?= $filter === $context['key'] ? ' is-active' : '' ?>" href="/aufgaben?kontext=<?= e($context['key']) ?>"<?= $filter === $context['key'] ? ' aria-current="true"' : '' ?>><?= e($context['label']) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php foreach ($labels as $key => $label) : ?>
        <?php if ($groups[$key] !== []) : ?>
    <section class="task-group task-group--<?= e($key) ?>" aria-labelledby="g-<?= e($key) ?>">
        <h2 id="g-<?= e($key) ?>"><?= e($label) ?> <span class="muted small">(<?= e(count($groups[$key])) ?>)</span></h2>
        <ul class="tasks">
            <?php foreach ($groups[$key] as $task) : ?>
            <li class="task">
                <form method="post" action="/aufgaben/<?= e($task->id) ?>/erledigt">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button class="task__check" type="submit" aria-label="Erledigt: <?= e($task->title) ?>"></button>
                </form>
                <a class="task__title" href="/aufgaben/<?= e($task->id) ?>"><?= e($task->title) ?></a>
                <span class="task__meta small muted">
                    <?php if ($task->dueDate !== null) : ?><span class="tnum"><?= e($dueText($task->dueDate)) ?></span><?php endif; ?>
                    <?php if ($task->recurrence !== null) : ?> · <?= e($recurrences[$task->recurrence] ?? 'wiederkehrend') ?><?php endif; ?>
                    <?php if ($task->context !== null) : ?> · <?= e($contextLabel[$task->context] ?? $task->context) ?><?php endif; ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
    </section>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($total === 0) : ?>
    <p class="card muted">Keine offenen Aufgaben.</p>
    <?php endif; ?>

    <?php if ($done !== []) : ?>
    <details class="task-done">
        <summary>Zuletzt erledigt</summary>
        <ul class="tasks">
            <?php foreach ($done as $task) : ?>
            <li class="task task--done">
                <form method="post" action="/aufgaben/<?= e($task->id) ?>/wieder-oeffnen">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button class="task__check is-done" type="submit" aria-label="Wieder öffnen: <?= e($task->title) ?>">✓</button>
                </form>
                <span class="task__title"><?= e($task->title) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>

    <p class="small"><a href="/export.json" download>Alle Daten als JSON exportieren</a></p>
</main>
