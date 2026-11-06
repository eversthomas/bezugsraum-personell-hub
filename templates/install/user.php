<?php
/** @var string $csrf */
/** @var string $username */
/** @var int $minLength */
/** @var list<string> $errors */
?>
<main class="auth-card card">
    <h1>Einrichten</h1>
    <p class="lead">Der Datenbankzugang ist gemerkt. Eine Konfigurationsdatei gibt es noch nicht. Als Nächstes der Benutzer.</p>
    <?php if ($errors !== []) : ?>
    <ul class="alert" role="alert">
        <?php foreach ($errors as $error) : ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <form method="post" action="/einrichten/benutzer" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="account-user">Benutzername</label>
            <input class="input" id="account-user" name="username" type="text" value="<?= e($username) ?>" autocomplete="off" required autofocus>
        </div>
        <div class="field">
            <label for="account-password">Passwort</label>
            <input class="input" id="account-password" name="password" type="password" autocomplete="new-password" required>
            <span class="muted">Mindestens <?= e((string) $minLength) ?> Zeichen.</span>
        </div>
        <button class="btn btn--block" type="submit">Weiter</button>
    </form>
</main>
