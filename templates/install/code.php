<?php
/** @var string|null $error */
/** @var string $csrf */
?>
<main class="auth-card card">
    <h1>Einrichten</h1>
    <p class="lead">Die Anwendung ist noch nicht eingerichtet. Ein Code liegt in <code>storage/install-code.txt</code>, außerhalb des Webroots.</p>
    <p>Lies den Code im Dateimanager des KAS oder per FTP und gib ihn hier ein.</p>
    <?php if (!empty($error)) : ?>
    <div class="alert" role="alert"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/einrichten/code" autocomplete="off">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="field">
            <label for="install-code">Einrichtungscode</label>
            <input class="input" id="install-code" name="code" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus>
        </div>
        <button class="btn btn--block" type="submit">Weiter</button>
    </form>
</main>
