<?php
/** @var list<string> $problems */
/** @var bool $ready */
?>
<main class="auth-card card">
    <h1>Einrichten</h1>
    <?php if ($problems !== []) : ?>
    <p class="lead">Die Systemprüfung ist nicht bestanden. Behebe die Punkte und lade die Seite neu.</p>
    <ul class="alert" role="alert">
        <?php foreach ($problems as $problem) : ?>
        <li><?= e($problem) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php elseif ($ready) : ?>
    <p class="lead">Die Systemprüfung ist in Ordnung.</p>
    <?php endif; ?>
</main>
