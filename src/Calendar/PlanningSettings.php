<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\SettingsRepository;

/**
 * Einstellungen für Zeitfenster-Vorschläge: Arbeitszeiten, Puffer, Zielkalender je Kontext, neutraler Standardtitel.
 */
final class PlanningSettings
{
    private const KEY = 'planning';

    /** Standard: Dienstliches und Betriebsrat → Outlook (AWO), Ehrenamt → Outlook (sysTelios), Privat → Google. */
    private const DEFAULT_TARGETS = [
        'dienstlich' => 'outlook',
        'betriebsrat' => 'outlook',
        'ehrenamt' => 'outlook',
        'privat' => 'google',
        'projekte' => 'outlook',
    ];

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function workingHours(): WorkingHours
    {
        $data = $this->data();

        return new WorkingHours($data['days'], $data['start'], $data['end'], $data['buffer']);
    }

    public function defaultTitle(): string
    {
        return $this->data()['title'];
    }

    /** @return array<string, string> Kontext-Schlüssel => Zielkalender ("outlook", "google" oder "ics") */
    public function targets(): array
    {
        return $this->data()['targets'];
    }

    public function targetFor(?string $context): string
    {
        return $this->data()['targets'][$context ?? ''] ?? 'ics';
    }

    /**
     * @param list<int> $days
     * @param array<string, string> $targets
     * @throws \InvalidArgumentException
     */
    public function save(array $days, int $startMinute, int $endMinute, int $buffer, string $title, array $targets): void
    {
        new WorkingHours($days, $startMinute, $endMinute, $buffer); // prüft die Werte
        $title = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $title) ?? '');
        if ($title === '' || mb_strlen($title) > 80) {
            throw new \InvalidArgumentException('Bitte einen neutralen Titel (höchstens 80 Zeichen) angeben.');
        }
        foreach ($targets as $target) {
            if (!in_array($target, ['outlook', 'google', 'ics'], true)) {
                throw new \InvalidArgumentException('Unbekannter Zielkalender.');
            }
        }
        $this->settings->set(self::KEY, [
            'days' => array_values(array_unique($days)),
            'start' => $startMinute,
            'end' => $endMinute,
            'buffer' => $buffer,
            'title' => $title,
            'targets' => $targets,
        ]);
    }

    /** @return array{days: list<int>, start: int, end: int, buffer: int, title: string, targets: array<string, string>} */
    private function data(): array
    {
        $stored = $this->settings->get(self::KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $days = array_values(array_filter(is_array($stored['days'] ?? null) ? $stored['days'] : [1, 2, 3, 4, 5], 'is_int'));
        $targets = is_array($stored['targets'] ?? null) ? array_filter($stored['targets'], 'is_string') : [];

        return [
            'days' => $days === [] ? [1, 2, 3, 4, 5] : $days,
            'start' => is_int($stored['start'] ?? null) ? $stored['start'] : 8 * 60,
            'end' => is_int($stored['end'] ?? null) ? $stored['end'] : 17 * 60,
            'buffer' => is_int($stored['buffer'] ?? null) ? $stored['buffer'] : 10,
            'title' => is_string($stored['title'] ?? null) && $stored['title'] !== '' ? $stored['title'] : 'Fokuszeit',
            'targets' => [...self::DEFAULT_TARGETS, ...$targets],
        ];
    }
}
