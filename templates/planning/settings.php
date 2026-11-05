<?php
/** @var string $csrf */
/** @var list<int> $days */
/** @var string $start */
/** @var string $end */
/** @var int $buffer */
/** @var string $planTitle */
/** @var array<string, string> $targets */
/** @var list<array{key: string, label: string, color: string}> $contexts */
/** @var array<string, string> $options */
/** @var array{type: string, message: string}|null $flash */
$weekdays = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
?>
<main class="page page--narrow">
    <h1>Planung</h1>
    <p class="muted">Einstellungen für „Zeit reservieren“: Wann Vorschläge möglich sind und in welchen Kalender ein Block gehört.</p>
    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>
    <form method="post" action="/einstellungen/planung" class="card">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <fieldset class="field swatches">
            <legend>Arbeitstage</legend>
            <div class="swatches__row">
                <?php foreach ($weekdays as $number => $label) : ?>
                <label class="chip"><input type="checkbox" name="days[]" value="<?= e($number) ?>"<?= in_array($number, $days, true) ? ' checked' : '' ?>> <?= e($label) ?></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="grid">
            <div class="field"><label for="p-start">Arbeitsbeginn</label><input class="input" id="p-start" name="start" type="time" value="<?= e($start) ?>" required></div>
            <div class="field"><label for="p-end">Arbeitsende</label><input class="input" id="p-end" name="end" type="time" value="<?= e($end) ?>" required></div>
            <div class="field"><label for="p-buffer">Puffer vor und nach Terminen (Minuten)</label><input class="input" id="p-buffer" name="buffer" type="number" min="0" max="120" step="5" value="<?= e($buffer) ?>" required></div>
        </div>
        <div class="field">
            <label for="p-title">Neutraler Standardtitel</label>
            <input class="input" id="p-title" name="title" type="text" maxlength="80" value="<?= e($planTitle) ?>" required>
            <p class="hint">Er erscheint im Kalender des Zielkontos. Keine Namen oder Inhalte einsetzen.</p>
        </div>
        <h2>Zielkalender je Kontext</h2>
        <?php foreach ($contexts as $context) : ?>
        <div class="field">
            <label for="t-<?= e($context['key']) ?>"><?= e($context['label']) ?></label>
            <select class="input" id="t-<?= e($context['key']) ?>" name="target[<?= e($context['key']) ?>]">
                <?php foreach ($options as $key => $label) : ?>
                <option value="<?= e($key) ?>"<?= ($targets[$context['key']] ?? 'ics') === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endforeach; ?>
        <div class="actions"><button class="btn" type="submit">Speichern</button></div>
    </form>
</main>
