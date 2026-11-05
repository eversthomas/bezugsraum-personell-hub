<?php
/** @var string $csrf */
/** @var string $heading */
?>
<header class="topbar">
    <nav class="topbar__nav" aria-label="Hauptnavigation">
        <a class="topbar__brand" href="/">Leitstand</a>
        <a href="/einstellungen/quellen">Kalenderquellen</a>
    </nav>
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
