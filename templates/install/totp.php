<?php
/** @var string $csrf */
/** @var string $secret */
/** @var string|null $qr */
/** @var list<string> $errors */
?>
<main class="auth-card card">
    <h1>Einrichten</h1>
    <p class="lead">Authenticator-App einrichten. Den QR-Code scannen oder den Schlüssel abtippen. Die Einrichtung endet erst nach einem gültigen Code.</p>
    <?php if ($errors !== []) : ?>
    <ul class="alert" role="alert">
        <?php foreach ($errors as $error) : ?>
        <li><?= e($error) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($qr !== null) : ?>
    <img class="qr" src="<?= e($qr) ?>" alt="QR-Code für den zweiten Faktor" width="220" height="220">
    <?php endif; ?>
    <p>
        <span class="muted">Schlüssel</span>
        <code class="secret"><?= e($secret) ?></code>
    </p>
    <form method="post" action="/einrichten/abschluss" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="totp-code">Code</label>
            <input class="input input--code" id="totp-code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus>
        </div>
        <button class="btn btn--block" type="submit">Einrichtung abschließen</button>
    </form>
</main>
