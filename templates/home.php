<?php
/** @var string $csrf */
?>
<header class="topbar">
    <strong>Übersicht</strong>
    <div class="topbar__actions">
        <div class="segment" role="group" aria-label="Darstellung">
            <button type="button" data-theme-set="auto" aria-pressed="false">Auto</button>
            <button type="button" data-theme-set="light" aria-pressed="false">Hell</button>
            <button type="button" data-theme-set="dark" aria-pressed="false">Dunkel</button>
        </div>
        <form method="post" action="/logout">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="btn btn--ghost" type="submit">Abmelden</button>
        </form>
    </div>
</header>
<main class="page">
    <h1>Angemeldet</h1>
    <p class="muted">Das Gerüst steht. Der Leitstand folgt in einem späteren Arbeitspaket.</p>
</main>
