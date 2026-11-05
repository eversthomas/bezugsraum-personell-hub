<?php
/** @var string|null $error */
/** @var string $csrf */
?>
<main class="auth-card card">
    <h1>Bestätigungscode</h1>
    <p class="lead">Sechsstelligen Code aus der Authenticator-App eingeben.</p>
    <?php if (!empty($error)) : ?>
    <div class="alert" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login/code">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="code">Code</label>
            <input class="input input--code" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus>
        </div>
        <label class="check">
            <input type="checkbox" name="remember" value="1">
            <span>Dieses Gerät 30 Tage merken</span>
        </label>
        <button class="btn btn--block" type="submit">Anmelden</button>
    </form>
    <p class="muted auth-card__back"><a href="/login">Zurück zur Anmeldung</a></p>
</main>
