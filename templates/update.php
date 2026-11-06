<?php
/** @var string $csrf */
/** @var list<string>|null $applied */
?>
<main class="auth-card card">
    <h1>Datenbank aktualisieren</h1>
    <?php if ($applied === null) : ?>
    <p class="lead">Es liegen Änderungen an der Datenbank bereit.</p>
    <p>Lege vorher eine Sicherung der Datenbank an. Danach spielt dieser Knopf die ausstehenden Änderungen ein.</p>
    <form method="post" action="/aktualisieren">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <button class="btn" type="submit">Jetzt aktualisieren</button>
    </form>
    <?php else : ?>
    <p class="lead">Die Aktualisierung ist durch.</p>
    <?php if ($applied === []) : ?>
    <p>Es war nichts mehr einzutragen.</p>
    <?php else : ?>
    <ul>
        <?php foreach ($applied as $name) : ?>
        <li><?= e($name) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p><a href="/">Weiter zum Leitstand</a></p>
    <?php endif; ?>
</main>
