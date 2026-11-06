<?php
/** @var string $username */
/** @var string $cronUrl */
/** @var string $cronUser */
/** @var string $cronPassword */
?>
<main class="auth-card card">
    <h1>Einrichtung abgeschlossen</h1>
    <p class="lead">Diese Cron-Angaben gibt es nur dieses eine Mal. Danach liegen nur noch Prüfsummen vor.</p>
    <p>Die Anmeldung gilt für <strong><?= e($username) ?></strong>. <a href="/login">Zur Anmeldung</a></p>
    <h2>Cron im KAS</h2>
    <p>Im KAS einen Cronjob anlegen: jede Minute, Aufruf per HTTPS. Das E-Mail-Feld leer lassen.</p>
    <p>
        <span class="muted">Adresse</span>
        <code class="secret"><?= e($cronUrl) ?></code>
    </p>
    <p>
        <span class="muted">Benutzer</span>
        <code class="secret"><?= e($cronUser) ?></code>
    </p>
    <p>
        <span class="muted">Passwort</span>
        <code class="secret"><?= e($cronPassword) ?></code>
    </p>
</main>
