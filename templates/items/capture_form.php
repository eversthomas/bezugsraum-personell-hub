<?php
/** @var string $csrf */
/** @var string $prefill */
/** @var string $origin */
?>
<form method="post" action="/erfassen" class="capture" data-capture>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="origin" value="<?= e($origin ?? 'text') ?>" data-capture-origin>
    <label class="visually-hidden" for="capture-text">Text</label>
    <textarea class="input capture__text" id="capture-text" name="text" rows="4" maxlength="10000" placeholder="Was geht dir durch den Kopf?" required data-capture-text><?= e($prefill ?? '') ?></textarea>
    <div class="actions capture__actions">
        <button type="button" class="btn btn--ghost" data-capture-voice title="Tastatur öffnen und das Mikrofon der Tastatur zum Diktieren nutzen">Sprechen</button>
        <button type="submit" class="btn">Speichern</button>
    </div>
</form>
