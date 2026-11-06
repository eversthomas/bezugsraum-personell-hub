<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Contracts\ModuleInterface;

/**
 * Führt die Cron-Aufträge der aktiven Module aus. Ein Lauf zur Zeit, höchstens etwa 50 Sekunden.
 * Ein einzelner Auftrag wird nicht zerteilt; ist die Zeit um, beginnt der nächste erst beim folgenden Aufruf.
 */
final class CronRunner
{
    public const BUDGET_SECONDS = 50;

    /** @var callable(): int */
    private $clock;

    /**
     * @param list<ModuleInterface> $modules
     * @param (callable(): int)|null $clock
     */
    public function __construct(
        private readonly array $modules,
        private readonly Container $container,
        private readonly Logger $logger,
        private readonly string $lockFile,
        ?callable $clock = null,
        private readonly int $budgetSeconds = self::BUDGET_SECONDS,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param list<ModuleInterface> $modules
     * @return list<CronJob>
     */
    public static function collect(array $modules): array
    {
        $jobs = [];
        foreach ($modules as $module) {
            array_push($jobs, ...$module->cronJobs());
        }

        return $jobs;
    }

    /** true, wenn dieser Aufruf den Lauf ausgeführt hat. false, wenn schon einer läuft. */
    public function run(): bool
    {
        $dir = dirname($this->lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $handle = fopen($this->lockFile, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return false;
        }

        try {
            $deadline = ($this->clock)() + $this->budgetSeconds;
            foreach (self::collect($this->modules) as $job) {
                if (($this->clock)() >= $deadline) {
                    break;
                }
                try {
                    ($job->run)($this->container);
                } catch (\Throwable $e) {
                    $this->logger->error('cron.' . $job->name, $e);
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return true;
    }
}
