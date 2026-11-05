<?php
/** @var string $title */
/** @var string $content */
/** @var string|null $csrf */
/** @var string|null $bodyClass */
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="color-scheme" content="light dark">
    <?php if (!empty($csrf)) : ?>
    <meta name="csrf" content="<?= e($csrf) ?>">
    <?php endif; ?>
    <title><?= e($title) ?></title>
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#16181d">
    <link rel="icon" href="/assets/icons/icon-192.png" type="image/png">
    <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
    <link rel="preload" href="/assets/fonts/atkinson-hyperlegible-next-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/views.css">
    <script src="/assets/js/theme.js"></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?php if (!empty($shell)) : ?>
<?php
$nav = [
    ['key' => 'leitstand', 'label' => 'Leitstand', 'href' => '/', 'icon' => 'L'],
    ['key' => 'aufgaben', 'label' => 'Aufgaben', 'href' => '/aufgaben', 'icon' => 'A'],
    ['key' => 'notizen', 'label' => 'Notizen', 'href' => '/notizen', 'icon' => 'N'],
    ['key' => 'erfassen', 'label' => 'Erfassen', 'href' => '/erfassen', 'icon' => '+'],
    ['key' => 'inbox', 'label' => 'Inbox', 'href' => '/inbox', 'icon' => 'I'],
    ['key' => 'quellen', 'label' => 'Kalenderquellen', 'href' => '/einstellungen/quellen', 'icon' => 'K'],
    ['key' => 'links', 'label' => 'Schnellzugriff pflegen', 'href' => '/einstellungen/links', 'icon' => 'S'],
];
$active = $active ?? '';
?>
<div class="shell" data-shell>
    <header class="mobilebar">
        <button type="button" class="mobilebar__button" data-nav-open aria-controls="sidebar" aria-expanded="false" aria-label="Menü öffnen">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>
        <strong><?= e($title) ?></strong>
    </header>
    <div class="scrim" data-nav-scrim hidden></div>
    <aside class="sidebar" id="sidebar" data-sidebar aria-label="Hauptnavigation">
        <div class="sidebar__head">
            <span class="sidebar__brand">Personal Hub</span>
            <button type="button" class="sidebar__close" data-nav-close aria-label="Menü schließen">✕</button>
        </div>
        <nav class="sidebar__nav">
            <?php foreach ($nav as $item) : ?>
            <a class="nav__item<?= $active === $item['key'] ? ' is-active' : '' ?>" href="<?= e($item['href']) ?>"<?= $active === $item['key'] ? ' aria-current="page"' : '' ?> title="<?= e($item['label']) ?>">
                <span class="nav__icon" aria-hidden="true"><?= e($item['icon']) ?></span>
                <span class="nav__label"><?= e($item['label']) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php
        $links = isset($quickLinks) ? $quickLinks() : [];
        $colors = isset($contextColors) ? $contextColors() : [];
        ?>
        <?php if ($links !== []) : ?>
        <nav class="sidebar__links" aria-label="Schnellzugriff">
            <p class="sidebar__heading">Schnellzugriff</p>
            <?php foreach ($links as $link) : ?>
            <a class="nav__item" href="<?= e($link->url) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($link->label) ?> (neuer Tab)">
                <span class="nav__icon <?= e(\App\Calendar\Palette::classFor($colors[$link->context ?? ''] ?? '')) ?> nav__icon--source" aria-hidden="true"><?= e($link->symbol) ?></span>
                <span class="nav__label"><?= e($link->label) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>
        <div class="sidebar__foot">
            <div class="segment" role="group" aria-label="Darstellung">
                <button type="button" data-theme-set="auto" aria-pressed="false">Auto</button>
                <button type="button" data-theme-set="light" aria-pressed="false">Hell</button>
                <button type="button" data-theme-set="dark" aria-pressed="false">Dunkel</button>
            </div>
            <form method="post" action="/logout">
                <input type="hidden" name="_csrf" value="<?= e($csrf ?? '') ?>">
                <button class="btn btn--ghost btn--block" type="submit">Abmelden</button>
            </form>
            <button type="button" class="sidebar__collapse" data-nav-collapse aria-pressed="false">Menü einklappen</button>
        </div>
    </aside>
    <div class="shell__main" data-shell-main>
<?= $content ?>
    </div>
</div>
<?php else : ?>
<?= $content ?>
<?php endif; ?>
<script type="module" src="/assets/js/app.js"></script>
</body>
</html>
