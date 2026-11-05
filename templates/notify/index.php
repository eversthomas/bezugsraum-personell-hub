<?php
/** @var string $csrf */
/** @var list<array<string, mixed>> $devices */
/** @var bool $configured */
/** @var array{type: string, message: string}|null $flash */
?>
<main class="page page--narrow">
    <h1>Benachrichtigungen</h1>
    <p class="muted">Erinnerungen für fällige Aufgaben (am Fälligkeitstag um 8:00 Uhr) und, je Kalenderquelle einstellbar, kurz vor Terminen. Die Nachrichten kommen als Push auch bei geschlossener App an.</p>

    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php if (!$configured) : ?>
    <div class="notice notice--warn">Push ist noch nicht eingerichtet. Mit <code>php bin/create-vapid.php</code> Schlüssel erzeugen und in <code>config/config.php</code> eintragen.</div>
    <?php else : ?>
    <section class="card push" data-push>
        <p data-push-status role="status">Status wird geprüft …</p>
        <div class="actions">
            <button type="button" class="btn" data-push-enable hidden>Auf diesem Gerät aktivieren</button>
            <button type="button" class="btn btn--ghost" data-push-test hidden>Testnachricht senden</button>
            <button type="button" class="btn btn--ghost btn--danger" data-push-disable hidden>Auf diesem Gerät ausschalten</button>
        </div>
    </section>
    <?php endif; ?>

    <h2>Geräte</h2>
    <?php if ($devices === []) : ?>
    <p class="card muted">Noch kein Gerät eingetragen.</p>
    <?php else : ?>
    <ul class="tasks linklist">
        <?php foreach ($devices as $device) : ?>
        <li class="task">
            <span class="task__title"><?= e($device['label']) ?> <span class="muted small">seit <?= e($device['since']) ?> · zuletzt erreicht: <?= e($device['lastOk']) ?></span></span>
            <form method="post" action="/einstellungen/benachrichtigungen/<?= e($device['id']) ?>/entfernen">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="btn btn--small btn--ghost btn--danger" type="submit">Entfernen</button>
            </form>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</main>
<script type="module" src="/assets/js/push.js"></script>
