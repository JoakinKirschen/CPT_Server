<?php
/*
 * Keys made in StructuralDocs as library keys (1.10.0), against a real test
 * database that also holds StructuralDocs's two tables, as on a host where both
 * use one database.
 */

declare(strict_types=1);

/** StructuralDocs's users and access_keys tables as its schema 1 makes them, with accounts by name. */
function structuraldocs_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE users (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL,
        password_hash VARCHAR(255) NOT NULL, sso_subject VARCHAR(255) NULL, library VARCHAR(8) NOT NULL DEFAULT '',
        library_group VARCHAR(64) NOT NULL DEFAULT '', created_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec('CREATE TABLE access_keys (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, name VARCHAR(100) NOT NULL,
        key_hash VARCHAR(64) NOT NULL UNIQUE, created_at BIGINT NOT NULL, used_at BIGINT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** An StructuralDocs account with a key; returns the key as StructuralDocs shows it once. */
function structuraldocs_account(PDO $pdo, string $email, string $library = '', string $group = ''): string
{
    $pdo->prepare('INSERT INTO users (email, name, password_hash, library, library_group, created_at) VALUES (?, ?, ?, ?, ?, 0)')
        ->execute([$email, ucfirst(strtok($email, '@')), 'x', $library, $group]);
    $key = 'sd_' . bin2hex(random_bytes(20));
    $pdo->prepare('INSERT INTO access_keys (user_id, name, key_hash, created_at) VALUES (?, ?, ?, 0)')
        ->execute([(int)$pdo->lastInsertId(), 'Structural Canvas', hash('sha256', $key)]);
    return $key;
}

function structuraldocs_rights(PDO $pdo, string $email, string $library, string $group = ''): void
{
    $pdo->prepare('UPDATE users SET library = ?, library_group = ? WHERE email = ?')->execute([$library, $group, $email]);
}

return [
    'an StructuralDocs key is a library key with the rights its administrator set' => function (): void {
        $t = new TestApp(['structuraldocs' => ['enabled' => true]]);
        structuraldocs_tables($t->pdo);
        $writer = structuraldocs_account($t->pdo, 'ann@example.com', 'write', 'office');
        $reader = structuraldocs_account($t->pdo, 'bea+site@example.com', 'read');
        $nobody = structuraldocs_account($t->pdo, 'cas@example.com');

        $ann = $t->get('/', $writer);
        same(200, $ann->status);
        same('ann@example.com', $ann->data()['user'], 'named by the account\'s address');
        same(true, $ann->data()['canWrite']);
        same('office', $ann->data()['group']);
        same(false, $ann->data()['anonymous']);
        $bea = $t->get('/', $reader)->data();
        same('bea_site@example.com', $bea['user'], 'what a user name cannot hold is replaced');
        same(false, $bea['canWrite']);
        same(null, $bea['group']);
        same(401, $t->get('/', $nobody)->status, 'an account without rights in the library');
        same(401, $t->get('/', 'sd_' . str_repeat('0', 40))->status, 'a key StructuralDocs does not have');
        same(401, $t->get('/', 'sd_short')->status);

        // One library user per account, however often it comes.
        $t->get('/', $writer);
        same(2, (int)$t->pdo->query('SELECT COUNT(*) FROM api_users WHERE structuraldocs_user_id IS NOT NULL')->fetchColumn());
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM api_users WHERE key_hash IS NOT NULL')->fetchColumn(), 'StructuralDocs\'s key is not kept here');

        // A writer uploads; a reader may not.
        $gef = upload_gef();
        $upload = ['name' => 'Site A S1', 'content' => $gef, 'latitude' => 51.0, 'longitude' => 4.0];
        $created = $t->postJson('/cpt', $writer, $upload);
        same(201, $created->status);
        same(403, $t->postJson('/cpt', $reader, $upload)->status);
        same(200, $t->get('/cpt/' . $created->data()['id'], $writer)->status, 'the upload is its account\'s');
    },

    'the rights follow StructuralDocs; a user revoked in the library stays out' => function (): void {
        $t = new TestApp(['structuraldocs' => ['enabled' => true]]);
        structuraldocs_tables($t->pdo);
        $key = structuraldocs_account($t->pdo, 'ann@example.com', 'write', 'office');
        same(true, $t->get('/', $key)->data()['canWrite']);
        structuraldocs_rights($t->pdo, 'ann@example.com', 'read', 'site-team');
        $now = $t->get('/', $key)->data();
        same(false, $now['canWrite']);
        same('site-team', $now['group']);
        same('ann@example.com', $now['user'], 'the user stays the same one');
        structuraldocs_rights($t->pdo, 'ann@example.com', '');
        same(401, $t->get('/', $key)->status, 'rights taken away in StructuralDocs');
        structuraldocs_rights($t->pdo, 'ann@example.com', 'write');
        same(200, $t->get('/', $key)->status);
        // Removing the key in StructuralDocs ends it here too.
        $t->pdo->exec('DELETE FROM access_keys');
        same(401, $t->get('/', $key)->status);

        $other = structuraldocs_account($t->pdo, 'dan@example.com', 'write');
        same(200, $t->get('/', $other)->status);
        $t->keys->revoke('dan@example.com');
        same(401, $t->get('/', $other)->status, 'revoked with bin/keys.php');
        same(1, (int)$t->pdo->query("SELECT COUNT(*) FROM api_users WHERE name = 'dan@example.com'")->fetchColumn());
    },

    'a key StructuralDocs (2.16.0) let expire is refused here too; one without an end is not (1.11.0)' => function (): void {
        $t = new TestApp(['structuraldocs' => ['enabled' => true]]);
        structuraldocs_tables($t->pdo);
        // Before StructuralDocs 2.16.0 there is no expires_at: keys are read as before.
        $key = structuraldocs_account($t->pdo, 'ann@example.com', 'write');
        same(200, $t->get('/', $key)->status, 'a StructuralDocs without expires_at');
        $t->pdo->exec('ALTER TABLE access_keys ADD COLUMN expires_at BIGINT NULL');
        // StructuralDocs upgraded: the next request (a new process on the host) reads the end.
        $t->app = new CptLibrary\App($t->config, $t->pdo, $t->clock, $t->dov, new CptLibrary\Logger(test_log_dir(), $t->clock));
        $t2 = $t;
        $now = $t2->clock->now()->getTimestamp() * 1000;
        same(200, $t2->get('/', $key)->status, 'a key made by hand has no end');
        $t2->pdo->prepare('UPDATE access_keys SET expires_at = ?')->execute([$now + 60000]);
        same(200, $t2->get('/', $key)->status, 'a signed-in key before its end');
        $t2->pdo->prepare('UPDATE access_keys SET expires_at = ?')->execute([$now - 1]);
        same(401, $t2->get('/', $key)->status, 'a signed-in key past its end');
        same(0, (int)$t2->pdo->query('SELECT COUNT(*) FROM access_keys WHERE expires_at > ' . $now)->fetchColumn(), 'the library writes nothing to StructuralDocs\'s tables');
    },

    'a refused key is told why (1.12.0)' => function (): void {
        $refusal = function (TestApp $t, string $key): array {
            $response = $t->get('/', $key);
            same(401, $response->status);
            same('unauthorized', $response->data()['error'], 'the code stays the same for older programs');
            return $response->data();
        };
        $t = new TestApp(['structuraldocs' => ['enabled' => true]]);
        structuraldocs_tables($t->pdo);
        $t->pdo->exec('ALTER TABLE access_keys ADD COLUMN expires_at BIGINT NULL');
        $nobody = structuraldocs_account($t->pdo, 'cas@example.com');
        $refused = $refusal($t, $nobody);
        same('no_library_rights', $refused['reason'], 'an account without rights in the library');
        check(str_contains($refused['message'], 'Administration → Groups'), 'says where the rights are given');
        same('unknown_key', $refusal($t, 'sd_' . str_repeat('0', 40))['reason'], 'a key StructuralDocs does not have');
        same('unknown_key', $refusal($t, 'cptlib_' . str_repeat('A', 40))['reason'], 'a library key it does not have');
        same('unknown_key', $refusal($t, 'sd_short')['reason'], 'not a key at all');

        $key = structuraldocs_account($t->pdo, 'ann@example.com', 'write');
        $now = $t->clock->now()->getTimestamp() * 1000;
        $t->pdo->prepare('UPDATE access_keys SET expires_at = ? WHERE user_id = (SELECT id FROM users WHERE email = ?)')->execute([$now - 1, 'ann@example.com']);
        same('key_expired', $refusal($t, $key)['reason'], 'a sign-in StructuralDocs let expire');
        $t->pdo->exec('UPDATE access_keys SET expires_at = NULL');
        same(200, $t->get('/', $key)->status);
        $t->keys->revoke('ann@example.com');
        same('key_revoked', $refusal($t, $key)['reason'], 'a user revoked with bin/keys.php');

        $own = $t->keys->create('bea');
        $t->keys->revoke('bea');
        same('unknown_key', $refusal($t, $own)['reason'], 'a revoked library key is no longer known');

        $missing = $t->postJson('/cpt', null, []);
        same(401, $missing->status);
        same(false, array_key_exists('reason', $missing->data()), 'no key sent: nothing was refused');
    },

    'a StructuralDocs key at a library that does not accept them names the setting (1.12.0)' => function (): void {
        $off = new TestApp();
        structuraldocs_tables($off->pdo);
        $key = structuraldocs_account($off->pdo, 'ann@example.com', 'write');
        $data = $off->get('/', $key)->data();
        same('structuraldocs_off', $data['reason']);
        check(str_contains($data['message'], "'structuraldocs' => ['enabled' => true]"), 'names the setting: ' . $data['message']);
        // A host whose config.php still has OnWord's setting, which 1.10.0 no longer reads.
        $old = new TestApp(['onword' => ['enabled' => true]]);
        structuraldocs_tables($old->pdo);
        $key = structuraldocs_account($old->pdo, 'ann@example.com', 'write');
        $data = $old->get('/', $key)->data();
        same('structuraldocs_off', $data['reason']);
        check(str_contains($data['message'], "still has the setting 'onword'"), 'names the old setting: ' . $data['message']);
    },

    'a name the library already has is not taken over' => function (): void {
        $t = new TestApp(['structuraldocs' => ['enabled' => true]]);
        structuraldocs_tables($t->pdo);
        $own = $t->keys->create('ann@example.com');
        $key = structuraldocs_account($t->pdo, 'ann@example.com', 'read');
        $name = $t->get('/', $key)->data()['user'];
        check($name !== 'ann@example.com' && str_starts_with($name, 'ann@example.com-sd'), "a name of its own, got {$name}");
        same('ann@example.com', $t->get('/', $own)->data()['user'], 'the library\'s own key still is its own user');
        same(true, $t->get('/', $own)->data()['canWrite']);
    },

    'StructuralDocs keys are refused unless the config switches them on' => function (): void {
        $t = new TestApp();
        structuraldocs_tables($t->pdo);
        $key = structuraldocs_account($t->pdo, 'ann@example.com', 'write');
        same(401, $t->get('/', $key)->status);
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM api_users')->fetchColumn());
        // Switched on without StructuralDocs's tables, a library key works as before.
        $plain = new TestApp(['structuraldocs' => ['enabled' => true]]);
        same('alice', $plain->get('/', $plain->keys->create('alice'))->data()['user']);
        throws(InvalidArgumentException::class, fn() => new TestApp(['structuraldocs' => ['enabled' => true, 'database' => 'a`b']]));
    },
];
