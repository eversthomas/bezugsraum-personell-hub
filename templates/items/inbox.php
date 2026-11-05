<?php
/** @var string $csrf */
/** @var list<array<string, mixed>> $rows */
/** @var list<array{key: string, label: string, color: string}> $contexts */
/** @var array{type: string, message: string}|null $flash */
$origins = ['text' => 'Text', 'voice' => 'Sprache', 'photo' => 'Foto', 'link' => 'Link', 'share' => 'Geteilt'];
?>
<main class="page">
    <h1>Inbox</h1>
    <p class="muted">Neue Einträge warten hier auf das Einsortieren als Aufgabe oder Notiz.</p>

    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($rows === []) : ?>
    <p class="card muted">Die Inbox ist leer.</p>
    <?php endif; ?>

    <?php foreach ($rows as $row) : ?>
    <article class="card inbox-item">
        <header>
            <h2><?= e($row['title']) ?></h2>
            <p class="small muted"><?= e($row['created']) ?> · <?= e($origins[$row['origin']] ?? 'Text') ?></p>
        </header>
        <?php foreach ($row['photos'] as $photoId) : ?>
        <a href="/media/<?= e($photoId) ?>" target="_blank" rel="noopener"><img class="photo" src="/media/<?= e($photoId) ?>" alt="Foto zum Eintrag" loading="lazy"></a>
        <?php endforeach; ?>
        <?php if ($row['body'] !== '') : ?>
        <p class="inbox-item__body"><?= nl2br(e($row['body'])) ?></p>
        <?php endif; ?>
        <form method="post" action="/inbox/<?= e($row['id']) ?>/einsortieren" class="inbox-item__sort">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label class="visually-hidden" for="ctx-<?= e($row['id']) ?>">Kontext</label>
            <select class="input" id="ctx-<?= e($row['id']) ?>" name="context">
                <option value="">Kontext wählen</option>
                <?php foreach ($contexts as $context) : ?>
                <option value="<?= e($context['key']) ?>"><?= e($context['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="due-<?= e($row['id']) ?>">Fällig am</label>
            <input class="input" id="due-<?= e($row['id']) ?>" name="due" type="date">
            <button class="btn btn--small" type="submit" name="type" value="task">Aufgabe</button>
            <button class="btn btn--small btn--ghost" type="submit" name="type" value="note">Notiz</button>
        </form>
        <form method="post" action="/inbox/<?= e($row['id']) ?>/loeschen" data-confirm="Eintrag wirklich löschen?">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="btn btn--small btn--ghost btn--danger" type="submit">Löschen</button>
        </form>
    </article>
    <?php endforeach; ?>
</main>
<script type="module" src="/assets/js/confirm.js"></script>
