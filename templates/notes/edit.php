<?php
/** @var string $csrf */
/** @var \App\Items\Item|null $note */
/** @var string $html  bereits sicher gerendertes Markdown */
/** @var list<array{key: string, label: string, color: string}> $contexts */
/** @var array{type: string, message: string}|null $flash */
$action = $note === null ? '/notizen' : '/notizen/' . $note->id;
?>
<main class="page">
    <h1><?= $note === null ? 'Neue Notiz' : 'Notiz' ?></h1>
    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <div class="note-edit">
        <form method="post" action="<?= e($action) ?>" class="card">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="field">
                <label for="n-title">Titel</label>
                <input class="input" id="n-title" name="title" type="text" maxlength="500" value="<?= e($note?->title ?? '') ?>" required>
            </div>
            <div class="field">
                <label for="n-context">Kontext</label>
                <select class="input" id="n-context" name="context">
                    <option value="">Kein Kontext</option>
                    <?php foreach ($contexts as $context) : ?>
                    <option value="<?= e($context['key']) ?>"<?= ($note?->context ?? '') === $context['key'] ? ' selected' : '' ?>><?= e($context['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="n-body">Text (Markdown)</label>
                <textarea class="input note-edit__body" id="n-body" name="body" rows="14"><?= e($note?->body ?? '') ?></textarea>
            </div>
            <div class="actions">
                <a class="btn btn--ghost" href="/notizen">Zurück</a>
                <button class="btn" type="submit">Speichern</button>
            </div>
        </form>
        <?php if ($note !== null) : ?>
        <section class="card note-preview" aria-label="Vorschau">
            <h2><?= e($note->title) ?></h2>
            <div class="markdown"><?= $html ?></div>
        </section>
        <?php endif; ?>
    </div>
    <?php if ($note !== null) : ?>
    <form method="post" action="/notizen/<?= e($note->id) ?>/loeschen" data-confirm="Notiz wirklich löschen?">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--small btn--ghost btn--danger" type="submit">Notiz löschen</button>
    </form>
    <?php endif; ?>
</main>
<script type="module" src="/assets/js/confirm.js"></script>
