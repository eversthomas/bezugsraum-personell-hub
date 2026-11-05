<?php
/** @var string|null $error */
/** @var string $username */
/** @var string $csrf */
?>
<main class="auth-card card">
    <h1>Anmelden</h1>
    <p class="lead">Bitte Zugangsdaten eingeben.</p>
    <?php if (!empty($error)) : ?>
    <div class="alert" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="username">Benutzername</label>
            <input class="input" id="username" name="username" type="text" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
        </div>
        <div class="field">
            <label for="password">Passwort</label>
            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn--block" type="submit">Weiter</button>
    </form>
</main>
