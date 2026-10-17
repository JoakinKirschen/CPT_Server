<?php

declare(strict_types=1);

namespace CptLibrary;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $socket = (string)$config->get('db.socket');
        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $config->get('db.name'))
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->get('db.host'), $config->int('db.port'), $config->get('db.name'));
        $pdo = new PDO($dsn, (string)$config->get('db.user'), (string)$config->get('db.pass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real server-side prepared statements, no client-side emulation.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $pdo;
    }

    /** Runs $work in a transaction and returns its result. */
    public static function transaction(PDO $pdo, callable $work): mixed
    {
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
