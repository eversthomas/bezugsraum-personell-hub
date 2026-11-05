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
    <link rel="preload" href="/assets/fonts/atkinson-hyperlegible-next-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/tokens.css">
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/components.css">
    <link rel="stylesheet" href="/assets/css/views.css">
    <script src="/assets/js/theme.js"></script>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
<?= $content ?>
<script type="module" src="/assets/js/app.js"></script>
</body>
</html>
