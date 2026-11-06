<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Dünner PDO-Wrapper. Ausschließlich Prepared Statements, Zeiten in UTC.
 */
final class Db
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, int|bool> */
    public static function options(?int $connectTimeoutSeconds = null): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($connectTimeoutSeconds !== null) {
            $options[PDO::ATTR_TIMEOUT] = $connectTimeoutSeconds;
            if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
                $options[(int) constant('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')] = $connectTimeoutSeconds;
            }
        }

        return $options;
    }

    public static function connect(Config $config, ?int $connectTimeoutSeconds = null): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config->string('db.host', '127.0.0.1'),
            $config->int('db.port', 3306),
            $config->string('db.name'),
            $config->string('db.charset', 'utf8mb4'),
        );
        $pdo = new PDO(
            $dsn,
            $config->string('db.user'),
            $config->string('db.password'),
            self::options($connectTimeoutSeconds),
        );
        $pdo->exec("SET time_zone = '+00:00'");

        return new self($pdo);
    }

    /** @param array<int|string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->run($sql, $params)->fetchAll();

        return $rows;
    }

    /** @param array<int|string, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Nur für Migrationen: rohe DDL-Anweisung ohne Parameter. */
    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    /** @param array<int|string, mixed> $params */
    private function run(string $sql, array $params): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }
}
