<?php
/** @var string $csrf */
/** @var \App\Items\Item $task */
/** @var list<array{key: string, label: string, color: string}> $contexts */
/** @var array<string, string> $recurrences */
/** @var array{type: string, message: string}|null $flash */
?>
<main class="page page--narrow">
    <h1>Aufgabe bearbeiten</h1>
    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <form method="post" action="/aufgaben/<?= e($task->id) ?>" class="card">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="t-title">Titel</label>
            <input class="input" id="t-title" name="title" type="text" maxlength="500" value="<?= e($task->title) ?>" required>
        </div>
        <div class="field">
            <label for="t-body">Notizen zur Aufgabe</label>
            <textarea class="input" id="t-body" name="body" rows="5"><?= e($task->body) ?></textarea>
        </div>
        <div class="grid">
            <div class="field">
                <label for="t-context">Kontext</label>
                <select class="input" id="t-context" name="context">
                    <option value="">Kein Kontext</option>
                    <?php foreach ($contexts as $context) : ?>
                    <option value="<?= e($context['key']) ?>"<?= $task->context === $context['key'] ? ' selected' : '' ?>><?= e($context['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="t-due">Fällig am</label>
                <input class="input" id="t-due" name="due" type="date" value="<?= e($task->dueDate ?? '') ?>">
            </div>
            <div class="field">
                <label for="t-rec">Wiederholung</label>
                <select class="input" id="t-rec" name="recurrence">
                    <option value="">Einmalig</option>
                    <?php foreach ($recurrences as $value => $label) : ?>
                    <option value="<?= e($value) ?>"<?= $task->recurrence === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="actions">
            <a class="btn btn--ghost" href="/aufgaben">Abbrechen</a>
            <button class="btn" type="submit">Speichern</button>
        </div>
    </form>
    <form method="post" action="/aufgaben/<?= e($task->id) ?>/loeschen" data-confirm="Aufgabe wirklich löschen?">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--small btn--ghost btn--danger" type="submit">Aufgabe löschen</button>
    </form>
</main>
<script type="module" src="/assets/js/confirm.js"></script>
