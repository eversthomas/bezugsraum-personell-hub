<?php
/** @var string $csrf */
/** @var list<array<string, mixed>> $rows */
/** @var string $query */
/** @var string|null $filter */
/** @var list<array{key: string, label: string, color: string}> $contexts */
/** @var array{type: string, message: string}|null $flash */
$contextLabel = [];
foreach ($contexts as $context) {
    $contextLabel[$context['key']] = $context['label'];
}
?>
<main class="page">
    <header class="page__head page__head--row">
        <h1>Notizen</h1>
        <a class="btn" href="/notizen/neu">+ Neue Notiz</a>
    </header>
    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <form method="get" action="/notizen" class="note-search" role="search">
        <label class="visually-hidden" for="note-q">Notizen durchsuchen</label>
        <input class="input" id="note-q" name="q" type="search" value="<?= e($query) ?>" placeholder="Titel und Text durchsuchen …">
        <?php if ($filter !== null) : ?><input type="hidden" name="kontext" value="<?= e($filter) ?>"><?php endif; ?>
        <button class="btn btn--ghost" type="submit">Suchen</button>
    </form>

    <nav class="chips" aria-label="Nach Kontext filtern">
        <a class="chip<?= $filter === null ? ' is-active' : '' ?>" href="/notizen<?= $query !== '' ? '?q=' . e(rawurlencode($query)) : '' ?>">Alle</a>
        <?php foreach ($contexts as $context) : ?>
        <a class="chip<?= $filter === $context['key'] ? ' is-active' : '' ?>" href="/notizen?kontext=<?= e($context['key']) ?><?= $query !== '' ? '&amp;q=' . e(rawurlencode($query)) : '' ?>"><?= e($context['label']) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($rows === []) : ?>
    <p class="card muted"><?= $query !== '' ? 'Keine Notiz gefunden.' : 'Noch keine Notizen.' ?></p>
    <?php endif; ?>
    <ul class="notes">
        <?php foreach ($rows as $row) : ?>
        <li class="card note">
            <a class="note__title" href="/notizen/<?= e($row['id']) ?>"><?= e($row['title']) ?></a>
            <?php if ($row['excerpt'] !== '') : ?><p class="note__excerpt"><?= e($row['excerpt']) ?></p><?php endif; ?>
            <p class="small muted"><?= e($row['updated']) ?><?php if ($row['context'] !== null) : ?> · <?= e($contextLabel[$row['context']] ?? $row['context']) ?><?php endif; ?></p>
        </li>
        <?php endforeach; ?>
    </ul>
</main>
