<?php
/* Migrations applied by the library itself, once per version of the code (1.9.0). */

declare(strict_types=1);

use CptLibrary\Migrator;

return [
    'a new version applies the pending migrations once, then only looks at its marker' => function (): void {
        $pdo = fresh_database();
        $dir = sys_get_temp_dir() . '/cptlib-state-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $migrator = new Migrator($pdo, dirname(__DIR__) . '/migrations', new FixedClock());
        $hasColumn = static fn(): bool => (bool)$pdo->query("SHOW COLUMNS FROM api_users LIKE 'onword_user_id'")->fetch();
        // A database as an older release left it: the last migration not applied.
        $pdo->exec('ALTER TABLE api_users DROP INDEX uq_api_users_onword, DROP COLUMN onword_user_id');
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '006_onword_accounts'");
        same(false, $hasColumn());

        same(['006_onword_accounts'], $migrator->ensure('9.9.0', $dir), 'the first request of the version migrates');
        same(true, $hasColumn());
        check(is_file($dir . '/schema-9.9.0.ok'), 'the marker is written, in a folder made for it');
        same([], $migrator->ensure('9.9.0', $dir), 'nothing is pending');

        // With the marker, the database is not looked at again for this version.
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '006_onword_accounts'");
        same([], $migrator->ensure('9.9.0', $dir));
        same(1, count($migrator->pending($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN))));
        $pdo->exec("INSERT INTO schema_migrations (version, applied_at) VALUES ('006_onword_accounts', UTC_TIMESTAMP())");
        // The next version looks again, and finds the database up to date.
        same([], $migrator->ensure('9.9.1', $dir));
        check(is_file($dir . '/schema-9.9.1.ok'));
        // A version is only ever part of a file name.
        $migrator->ensure('../../x y', $dir);
        check(is_file($dir . '/schema-.._.._x_y.ok') && !is_file(dirname($dir, 2) . '/x y.ok'));
    },

    'while another request migrates, a request waits, and gives up without marking the version done' => function (): void {
        $pdo = fresh_database();
        $other = CptLibrary\Database::connect(test_config());
        $dir = sys_get_temp_dir() . '/cptlib-state-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $migrator = new Migrator($pdo, dirname(__DIR__) . '/migrations', new FixedClock());
        same(1, (int)$other->query("SELECT GET_LOCK('cptlib_migrate', 1)")->fetchColumn());
        $started = microtime(true);
        $error = throws(RuntimeException::class, fn() => $migrator->ensure('9.9.0', $dir, 1));
        check(microtime(true) - $started >= 0.9, 'it waited for the other request');
        check(str_contains($error->getMessage(), 'still applying'));
        check(!is_file($dir . '/schema-9.9.0.ok'), 'the version is not marked done');
        $other->query("SELECT RELEASE_LOCK('cptlib_migrate')");
        same([], $migrator->ensure('9.9.0', $dir, 1), 'once the other is done it goes on');
        check(is_file($dir . '/schema-9.9.0.ok'));
    },
];
