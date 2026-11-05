<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Core\Crypto;
use App\Core\Logger;

/**
 * Verwaltung und Abruf der Kalenderquellen. Die URL liegt nur verschlüsselt in der Datenbank
 * und taucht weder in Antworten noch in Logs auf.
 */
final class CalendarService
{
    public const PAST_DAYS = 30;
    public const FUTURE_DAYS = 90;
    public const MIN_INTERVAL = 5;
    public const MAX_INTERVAL = 1440;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly SourceRepository $sources,
        private readonly EventRepository $events,
        private readonly IcsFetcher $fetcher,
        private readonly UrlGuard $guard,
        private readonly IcsParser $parser,
        private readonly Crypto $crypto,
        private readonly Logger $logger,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * Legt eine Quelle an (ohne Abruf). Die Adresse wird formal geprüft, aber nicht aufgerufen.
     *
     * @throws \InvalidArgumentException bei ungültigen Eingaben
     */
    public function addSource(string $name, string $context, string $color, string $url, int $intervalMin = 15): int
    {
        [$name, $color, $intervalMin] = $this->validate($name, $context, $color, $intervalMin);
        $hint = $this->hint($url);

        return $this->sources->create(
            $name,
            $context,
            $color,
            $this->crypto->encrypt(UrlGuard::normalize($url)),
            $hint,
            $intervalMin,
            $this->time(),
        );
    }

    /**
     * @throws \InvalidArgumentException bei ungültigen Eingaben
     */
    public function updateSource(int $id, string $name, string $context, string $color, int $intervalMin): void
    {
        $this->require($id);
        [$name, $color, $intervalMin] = $this->validate($name, $context, $color, $intervalMin);
        $this->sources->updateDetails($id, $name, $context, $color, $intervalMin);
    }

    /** Prüft eine Adresse durch einen echten Abruf, ohne etwas zu speichern. */
    public function test(string $url): TestResult
    {
        try {
            $this->guard->check($url);
            $result = $this->fetcher->fetch($url);
            $now = new \DateTimeImmutable('@' . $this->now());
            $events = $this->parser->parse(
                $result->body,
                $now->modify('-' . self::PAST_DAYS . ' days'),
                $now->modify('+' . self::FUTURE_DAYS . ' days'),
            );

            return new TestResult(true, count($events), null);
        } catch (FetchException $e) {
            return new TestResult(false, 0, $e->getMessage());
        }
    }

    public function replaceUrl(int $id, string $url): void
    {
        $this->require($id);
        $hint = $this->hint($url);
        $this->sources->replaceUrl($id, $this->crypto->encrypt(UrlGuard::normalize($url)), $hint);
    }

    public function pause(int $id): void
    {
        $this->require($id);
        $this->sources->setActive($id, false);
    }

    public function resume(int $id): void
    {
        $this->require($id);
        $this->sources->setActive($id, true);
    }

    public function remove(int $id): void
    {
        $this->require($id);
        $this->sources->delete($id);
    }

    /** @return list<Source> */
    public function sources(): array
    {
        return $this->sources->all();
    }

    /**
     * Ruft eine Quelle ab und ersetzt ihre Termine. Bei Fehlern bleibt der alte Stand erhalten
     * und der Fehler wird an der Quelle vermerkt.
     */
    public function refresh(int $id): bool
    {
        $source = $this->require($id);
        $now = $this->time();
        try {
            $url = $this->crypto->decrypt($source->urlEncrypted);
            $result = $this->fetcher->fetch($url, $source->etag);
            if ($result->notModified) {
                $this->sources->markSuccess($id, $source->etag, $now);

                return true;
            }
            $anchor = new \DateTimeImmutable('@' . $this->now());
            $events = $this->parser->parse(
                $result->body,
                $anchor->modify('-' . self::PAST_DAYS . ' days'),
                $anchor->modify('+' . self::FUTURE_DAYS . ' days'),
            );
            $this->events->replaceForSource($id, $events, $now);
            $this->sources->markSuccess($id, $result->etag, $now);

            return true;
        } catch (FetchException $e) {
            $this->sources->markFailure($id, $e->getMessage(), $now);
            $this->logger->log('warning', 'calendar.refresh_failed', ['source' => $id]);

            return false;
        } catch (\Throwable $e) {
            $this->sources->markFailure($id, 'Unerwarteter Fehler beim Abruf.', $now);
            $this->logger->error('calendar.refresh_error', $e);

            return false;
        }
    }

    /** Wird vom Cronjob aufgerufen: ruft alle fälligen Quellen ab und räumt alte Termine auf. */
    public function refreshDue(): int
    {
        $count = 0;
        foreach ($this->sources->due($this->time()) as $source) {
            $this->refresh($source->id);
            ++$count;
        }
        $this->events->purgeEndedBefore((new \DateTimeImmutable('@' . $this->now()))->modify('-' . self::PAST_DAYS . ' days'));

        return $count;
    }

    private function require(int $id): Source
    {
        return $this->sources->find($id) ?? throw new \InvalidArgumentException('Quelle nicht gefunden.');
    }

    /** @return array{string, string, int} */
    private function validate(string $name, string $context, string $color, int $intervalMin): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \InvalidArgumentException('Bitte einen Namen (höchstens 120 Zeichen) angeben.');
        }
        if (!$this->sources->contextExists($context)) {
            throw new \InvalidArgumentException('Unbekannter Kontext.');
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            throw new \InvalidArgumentException('Farbe muss wie #2563EB angegeben werden.');
        }

        return [$name, strtoupper($color), max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $intervalMin))];
    }

    /** Anzeigetext ohne geheimen Teil: nur der Servername. */
    private function hint(string $url): string
    {
        $parts = parse_url(UrlGuard::normalize($url));
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || $parts['host'] === '') {
            throw new \InvalidArgumentException('Bitte eine https-Adresse (oder webcal://) eingeben.');
        }

        return mb_substr(strtolower($parts['host']), 0, 150) . '/…';
    }

    private function now(): int
    {
        return ($this->clock)();
    }

    private function time(): string
    {
        return gmdate('Y-m-d H:i:s', $this->now());
    }
}
