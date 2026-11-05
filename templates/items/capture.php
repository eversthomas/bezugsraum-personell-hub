<?php
/** @var string $csrf */
/** @var string $prefill */
/** @var string $origin */
/** @var array{type: string, message: string}|null $flash */
?>
<main class="page page--narrow">
    <h1>Erfassen</h1>
    <p class="muted">Gedanken zuerst festhalten, später einsortieren.</p>

    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <?php require __DIR__ . '/capture_form.php'; ?>

    <p class="small muted" data-capture-status role="status"></p>
    <p><a href="/inbox">Zur Inbox</a></p>
</main>
<script type="module" src="/assets/js/capture.js"></script>
