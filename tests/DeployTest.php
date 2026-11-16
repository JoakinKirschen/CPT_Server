<?php
/* Migrations applied by the library itself, once per version of the code (1.9.0). */

declare(strict_types=1);

use CptLibrary\Migrator;

/** The database as 1.9.0 left it: OnWord's column, migration 007 not applied. */
function undo_structuraldocs_migration(PDO $pdo): void
{
    $pdo->exec('ALTER TABLE api_users DROP INDEX uq_api_users_structuraldocs, CHANGE COLUMN structuraldocs_user_id onword_user_id INT UNSIGNED NULL, ADD UNIQUE KEY uq_api_users_onword (onword_user_id)');
    $pdo->exec("DELETE FROM schema_migrations WHERE version = '007_structuraldocs_accounts'");
}

return [
    'the users made for OnWord accounts are unlinked, revoked and renamed; the others stay' => function (): void {
        $pdo = fresh_database();
        undo_structuraldocs_migration($pdo);
        $add = $pdo->prepare('INSERT INTO api_users (name, can_write, created_at, onword_user_id) VALUES (?, 1, UTC_TIMESTAMP(), ?)');
        $add->execute(['ann@example.com', 7]);
        $add->execute(['office', null]);
        $migrator = new Migrator($pdo, dirname(__DIR__) . '/migrations', new FixedClock());
        $dir = sys_get_temp_dir() . '/cptlib-state-' . getmypid() . '-' . bin2hex(random_bytes(4));
        same(['007_structuraldocs_accounts'], $migrator->ensure('9.9.0', $dir));
        $rows = $pdo->query('SELECT id, name, revoked_at IS NOT NULL AS revoked, structuraldocs_user_id FROM api_users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        same('ann@example.com-onword' . $rows[0]['id'], $rows[0]['name']);
        same([1, null], [(int)$rows[0]['revoked'], $rows[0]['structuraldocs_user_id']]);
        same(['office', 0], [$rows[1]['name'], (int)$rows[1]['revoked']], 'a user made with bin/keys.php is left alone');
    },

    'a new version applies the pending migrations once, then only looks at its marker' => function (): void {
        $pdo = fresh_database();
        $dir = sys_get_temp_dir() . '/cptlib-state-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $migrator = new Migrator($pdo, dirname(__DIR__) . '/migrations', new FixedClock());
        $hasColumn = static fn(): bool => (bool)$pdo->query("SHOW COLUMNS FROM api_users LIKE 'structuraldocs_user_id'")->fetch();
        // A database as an older release left it: the last migration not applied.
        undo_structuraldocs_migration($pdo);
        same(false, $hasColumn());

        same(['007_structuraldocs_accounts'], $migrator->ensure('9.9.0', $dir), 'the first request of the version migrates');
        same(true, $hasColumn());
        check(is_file($dir . '/schema-9.9.0.ok'), 'the marker is written, in a folder made for it');
        same([], $migrator->ensure('9.9.0', $dir), 'nothing is pending');

        // With the marker, the database is not looked at again for this version.
        $pdo->exec("DELETE FROM schema_migrations WHERE version = '007_structuraldocs_accounts'");
        same([], $migrator->ensure('9.9.0', $dir));
        same(1, count($migrator->pending($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN))));
        $pdo->exec("INSERT INTO schema_migrations (version, applied_at) VALUES ('007_structuraldocs_accounts', UTC_TIMESTAMP())");
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
