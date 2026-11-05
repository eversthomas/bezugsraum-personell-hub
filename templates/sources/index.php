<?php
/** @var string $csrf */
/** @var list<array<string, mixed>> $rows */
/** @var list<array{key: string, label: string, color: string}> $contexts */
/** @var array<string, array{hex: string, label: string}> $palette */
/** @var array{type: string, message: string}|null $flash */

$badge = [
    'ok' => ['Aktuell', 'badge--ok'],
    'new' => ['Noch nicht abgerufen', 'badge--info'],
    'error' => ['Abruf fehlgeschlagen', 'badge--warn'],
    'paused' => ['Pausiert', 'badge--muted'],
];
$intervals = [15 => 'alle 15 Minuten', 30 => 'alle 30 Minuten', 60 => 'stündlich'];

?>
<main class="page">
    <header class="page__head">
        <div>
            <h1>Kalenderquellen</h1>
            <p class="muted">Jede Quelle ist ein lesender ICS-Link aus einem deiner Konten. Wechselt ein Konto oder fällt es weg, ersetzt oder entfernst du hier nur die Quelle. Der Rest bleibt.</p>
        </div>
    </header>

    <?php if ($flash !== null) : ?>
    <div class="notice notice--<?= e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <details class="card source-new" <?= $rows === [] ? 'open' : '' ?>>
        <summary class="btn">+ Quelle hinzufügen</summary>
        <form method="post" action="/einstellungen/quellen" class="source-form" data-source-form>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="grid">
                <div class="field">
                    <label for="new-name">Bezeichnung</label>
                    <input class="input" id="new-name" name="name" type="text" maxlength="120" placeholder="z. B. Neuer Arbeitgeber" required>
                </div>
                <div class="field">
                    <label for="new-context">Kontext</label>
                    <select class="input" id="new-context" name="context">
                        <?php foreach ($contexts as $context) : ?>
                        <option value="<?= e($context['key']) ?>"><?= e($context['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="new-url">ICS-Link</label>
                <input class="input" id="new-url" name="url" type="password" autocomplete="off" spellcheck="false" placeholder="https://outlook.office365.com/owa/calendar/…/calendar.ics" required data-source-url>
                <p class="hint">Wird verschlüsselt gespeichert und danach nie wieder vollständig angezeigt. Für Betriebsrat und Dienstliches beim Veröffentlichen „Titel und Orte“ wählen.</p>
            </div>
            <div class="grid">
                <fieldset class="field swatches">
                    <legend>Farbe</legend>
                    <div class="swatches__row">
                        <?php foreach ($palette as $key => $color) : ?>
                        <label class="swatch src-<?= e($key) ?>">
                            <input type="radio" name="color" value="<?= e($key) ?>" <?= $key === 'blue' ? 'checked' : '' ?>>
                            <span class="swatch__chip"></span>
                            <span class="visually-hidden"><?= e($color['label']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="field">
                    <label for="new-interval">Aktualisierung</label>
                    <select class="input" id="new-interval" name="interval">
                        <?php foreach ($intervals as $minutes => $label) : ?>
                        <option value="<?= e($minutes) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="notice" role="status" hidden data-source-test-result></p>
            <div class="actions">
                <button type="button" class="btn btn--ghost" data-source-test>Verbindung testen</button>
                <button type="submit" class="btn">Speichern</button>
            </div>
        </form>
    </details>

    <section class="card source-list" aria-label="Eingerichtete Quellen">
        <?php if ($rows === []) : ?>
        <p class="muted">Noch keine Quelle eingerichtet.</p>
        <?php endif; ?>
        <?php foreach ($rows as $row) : ?>
        <?php [$badgeText, $badgeClass] = $badge[(string) $row['status']]; ?>
        <article class="source <?= e($row['class']) ?> <?= $row['active'] ? '' : 'source--paused' ?>">
            <div class="source__head">
                <span class="source__dot" aria-hidden="true"></span>
                <div class="source__title">
                    <strong><?= e($row['name']) ?></strong>
                    <span class="muted small"><?= e($row['context']) ?> · <?= e($row['hint']) ?></span>
                </div>
                <span class="badge <?= e($badgeClass) ?>"><?= e($badgeText) ?></span>
                <div class="source__actions">
                    <form method="post" action="/einstellungen/quellen/<?= e($row['id']) ?>/abrufen">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="btn btn--small btn--ghost" type="submit">Jetzt abrufen</button>
                    </form>
                    <form method="post" action="/einstellungen/quellen/<?= e($row['id']) ?>/<?= $row['active'] ? 'pausieren' : 'fortsetzen' ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="btn btn--small btn--ghost" type="submit"><?= $row['active'] ? 'Pausieren' : 'Fortsetzen' ?></button>
                    </form>
                    <form method="post" action="/einstellungen/quellen/<?= e($row['id']) ?>/entfernen" data-confirm="Quelle „<?= e($row['name']) ?>“ und ihre Termine wirklich entfernen?">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button class="btn btn--small btn--ghost btn--danger" type="submit">Entfernen</button>
                    </form>
                </div>
            </div>
            <dl class="source__facts small">
                <div><dt>Link</dt><dd class="tnum"><?= e($row['hint']) ?></dd></div>
                <div><dt>Abruf</dt><dd>alle <?= e($row['interval']) ?> Min. · zuletzt erfolgreich: <?= e($row['lastOk']) ?></dd></div>
            </dl>
            <?php if (!empty($row['error'])) : ?>
            <p class="notice notice--warn small"><?= e($row['error']) ?> Die zuletzt geladenen Termine bleiben sichtbar.</p>
            <?php endif; ?>
            <details class="source__more">
                <summary class="small">Link ersetzen</summary>
                <form method="post" action="/einstellungen/quellen/<?= e($row['id']) ?>/link" class="source__replace">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <label class="visually-hidden" for="url-<?= e($row['id']) ?>">Neuer ICS-Link</label>
                    <input class="input" id="url-<?= e($row['id']) ?>" name="url" type="password" autocomplete="off" spellcheck="false" placeholder="Neuer ICS-Link" required>
                    <button class="btn btn--small" type="submit">Ersetzen</button>
                </form>
            </details>
        </article>
        <?php endforeach; ?>
    </section>
</main>
<script type="module" src="/assets/js/sources.js"></script>
