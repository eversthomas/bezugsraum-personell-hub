<?php
/** @var string $csrf */
/** @var string $host */
/** @var string $port */
/** @var string $name */
/** @var string $user */
/** @var string $url */
/** @var string $timezone */
/** @var list<string> $errors */
/** @var string|null $notice */
?>
<main class="auth-card card">
    <h1>Einrichten</h1>
    <p class="lead">Die Systemprüfung ist in Ordnung. Als Nächstes der Datenbankzugang.</p>
    <?php if ($notice !== null) : ?>
    <p class="alert" role="status"><?= e($notice) ?></p>
    <?php endif; ?>
    <?php if ($errors !== []) : ?>
    <ul class="alert" role="alert">
        <?php foreach ($errors as $error) : ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="post" action="/einrichten/datenbank/testen" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="db-host">Host</label>
            <input class="input" id="db-host" name="host" type="text" value="<?= e($host) ?>" required autofocus>
        </div>
        <div class="field">
            <label for="db-port">Port</label>
            <input class="input" id="db-port" name="port" type="text" inputmode="numeric" value="<?= e($port) ?>" required>
        </div>
        <div class="field">
            <label for="db-name">Datenbankname</label>
            <input class="input" id="db-name" name="name" type="text" value="<?= e($name) ?>" required>
        </div>
        <div class="field">
            <label for="db-user">Benutzer</label>
            <input class="input" id="db-user" name="user" type="text" value="<?= e($user) ?>" autocomplete="off" required>
        </div>
        <div class="field">
            <label for="db-password">Passwort</label>
            <input class="input" id="db-password" name="password" type="password" autocomplete="new-password">
        </div>
        <div class="field">
            <label for="app-url">App-Adresse</label>
            <input class="input" id="app-url" name="url" type="url" value="<?= e($url) ?>" required>
        </div>
        <div class="field">
            <label for="app-timezone">Zeitzone</label>
            <input class="input" id="app-timezone" name="timezone" type="text" value="<?= e($timezone) ?>" required>
        </div>
        <div class="actions">
            <button class="btn" type="submit">Verbindung prüfen</button>
            <button class="btn btn--ghost" type="submit" formaction="/einrichten/datenbank">Zugang merken</button>
        </div>
    </form>
</main>
