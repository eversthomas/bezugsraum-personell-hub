<?php
/** @var string $csrf */
/** @var list<array<string, mixed>> $devices */
/** @var int $minLength */
/** @var array{type: string, message: string}|null $flash */
?>
<main class="page page--narrow">
    <h1>Sicherheit</h1>
    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <h2>Passwort ändern</h2>
    <form method="post" action="/einstellungen/sicherheit/passwort" class="card">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="pw-current">Aktuelles Passwort</label>
            <input class="input" id="pw-current" name="current" type="password" autocomplete="current-password" required>
        </div>
        <div class="field">
            <label for="pw-new">Neues Passwort (mindestens <?= e($minLength) ?> Zeichen)</label>
            <input class="input" id="pw-new" name="new" type="password" autocomplete="new-password" minlength="<?= e($minLength) ?>" required>
        </div>
        <div class="field">
            <label for="pw-confirm">Neues Passwort wiederholen</label>
            <input class="input" id="pw-confirm" name="confirm" type="password" autocomplete="new-password" minlength="<?= e($minLength) ?>" required>
        </div>
        <div class="actions"><button class="btn" type="submit">Passwort ändern</button></div>
    </form>

    <h2>Gemerkte Geräte</h2>
    <p class="muted">Auf diesen Geräten ist nach dem Passwort kein Code nötig. Entfernte Geräte fragen beim nächsten Anmelden wieder nach dem Code.</p>
    <?php if ($devices === []) : ?>
    <p class="card muted">Kein Gerät gemerkt.</p>
    <?php else : ?>
    <ul class="tasks linklist">
        <?php foreach ($devices as $device) : ?>
        <li class="task">
            <span class="task__title"><?= e($device['label']) ?> <span class="muted small">zuletzt <?= e($device['lastSeen']) ?> · gültig bis <?= e($device['expires']) ?></span></span>
            <form method="post" action="/einstellungen/sicherheit/geraete/<?= e($device['id']) ?>/entfernen">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn--small btn--ghost btn--danger" type="submit">Entfernen</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="/einstellungen/sicherheit/geraete/alle/entfernen" data-confirm="Alle gemerkten Geräte entfernen?">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn btn--small btn--ghost btn--danger" type="submit">Alle entfernen</button>
    </form>
    <?php endif; ?>
</main>
<script type="module" src="/assets/js/confirm.js"></script>
