<?php
/** @var string $csrf */
/** @var list<\App\Links\QuickLink> $links */
/** @var list<array{key: string, label: string, color: string}> $contexts */
/** @var array{type: string, message: string}|null $flash */
?>
<main class="page page--narrow">
    <h1>Schnellzugriff</h1>
    <p class="muted">Links zu deinen Webanwendungen. Sie erscheinen in der Seitenleiste und im mobilen Menü und öffnen in einem neuen Tab. Es sind nur https-Adressen erlaubt.</p>

    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <form method="post" action="/einstellungen/links" class="card">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="grid">
            <div class="field">
                <label for="l-label">Bezeichnung</label>
                <input class="input" id="l-label" name="label" type="text" maxlength="80" required>
            </div>
            <div class="field">
                <label for="l-context">Kontext</label>
                <select class="input" id="l-context" name="context">
                    <option value="">Kein Kontext</option>
                    <?php foreach ($contexts as $context) : ?>
                    <option value="<?= e($context['key']) ?>"><?= e($context['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="field">
            <label for="l-url">Adresse</label>
            <input class="input" id="l-url" name="url" type="url" maxlength="2000" placeholder="https://" required>
        </div>
        <div class="actions"><button class="btn" type="submit">Hinzufügen</button></div>
    </form>

    <?php if ($links !== []) : ?>
    <ul class="tasks linklist">
        <?php foreach ($links as $link) : ?>
        <li class="task">
            <span class="task__title"><?= e($link->label) ?> <span class="muted small"><?= e(parse_url($link->url, PHP_URL_HOST) ?: '') ?></span></span>
            <form method="post" action="/einstellungen/links/<?= e($link->id) ?>/verschieben">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn--small btn--ghost" type="submit" name="direction" value="up" aria-label="Nach oben">↑</button>
                <button class="btn btn--small btn--ghost" type="submit" name="direction" value="down" aria-label="Nach unten">↓</button>
            </form>
            <form method="post" action="/einstellungen/links/<?= e($link->id) ?>/entfernen">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn--small btn--ghost btn--danger" type="submit">Entfernen</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</main>
