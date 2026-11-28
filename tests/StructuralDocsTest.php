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

/** StructuralDocs's library_keys (2.19.0): keys its administrator makes for the library, each in a group or none. */
function structuraldocs_library_keys(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE library_keys (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL UNIQUE,
        key_hash VARCHAR(64) NOT NULL UNIQUE, key_hint VARCHAR(4) NOT NULL, group_id INT NULL, library VARCHAR(8) NOT NULL DEFAULT '',
        library_group VARCHAR(64) NOT NULL DEFAULT '', created_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** A key made in StructuralDocs's CPT library keys tab, with its group's rights; returns the key as shown once. */
function structuraldocs_library_key(PDO $pdo, string $name, string $library = '', string $group = ''): string
{
    $key = 'sdlib_' . bin2hex(random_bytes(20));
    $pdo->prepare('INSERT INTO library_keys (name, key_hash, key_hint, library, library_group, created_at) VALUES (?, ?, ?, ?, ?, 0)')
        ->execute([$name, hash('sha256', $key), substr($key, -4), $library, $group]);
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

    'a key StructuralDocs\'s administrator made for the library has its group\'s rights (1.13.0)' => function (): void {
        $t = new TestApp(['structuraldocs' => ['enabled' => true]]);
        structuraldocs_tables($t->pdo);
        $before = 'sdlib_' . bin2hex(random_bytes(20));
        same('unknown_key', $t->get('/', $before)->data()['reason'], 'a StructuralDocs before 2.19.0 has no such keys');
        structuraldocs_library_keys($t->pdo);
        $laptop = structuraldocs_library_key($t->pdo, 'Site laptop', 'write', 'Office-Ghent');
        $reader = structuraldocs_library_key($t->pdo, 'Tablet', 'read', 'Site-team');
        $none = structuraldocs_library_key($t->pdo, 'Spare', '', '');

        $me = $t->get('/', $laptop)->data();
        same('Site_laptop', $me['user'], 'named after the key');
        same(true, $me['canWrite']);
        same('Office-Ghent', $me['group']);
        same(false, $t->get('/', $reader)->data()['canWrite']);
        $refused = $t->get('/', $none)->data();
        same('no_library_rights', $refused['reason'], 'a key in no group, or in one without rights');
        check(str_contains($refused['message'], 'CPT library keys'), 'says where a key\'s group is set: ' . $refused['message']);
        same('unknown_key', $t->get('/', 'sdlib_' . str_repeat('0', 40))->data()['reason'], 'a key StructuralDocs does not have');
        same('unknown_key', $t->get('/', 'sdlib_short')->data()['reason']);

        // One library user per key, apart from accounts; StructuralDocs's key is not kept here.
        $t->get('/', $laptop);
        same(2, (int)$t->pdo->query('SELECT COUNT(*) FROM api_users WHERE structuraldocs_key_id IS NOT NULL')->fetchColumn());
        same(0, (int)$t->pdo->query('SELECT COUNT(*) FROM api_users WHERE key_hash IS NOT NULL OR structuraldocs_user_id IS NOT NULL')->fetchColumn());

        // It uploads to its group: an account of the same group sees the upload, as the key does.
        $gef = upload_gef();
        $created = $t->postJson('/cpt', $laptop, ['name' => 'Site B S1', 'content' => $gef, 'latitude' => 51.0, 'longitude' => 4.0, 'location' => ['method' => 'survey'], 'visibility' => 'group']);
        same(201, $created->status);
        $colleague = structuraldocs_account($t->pdo, 'ann@example.com', 'read', 'Office-Ghent');
        same(200, $t->get('/cpt/' . $created->data()['id'], $colleague)->status, 'an account of the key\'s group');
        same(404, $t->get('/cpt/' . $created->data()['id'], $reader)->status, 'a key of another group');

        // Its group changed in StructuralDocs: the rights follow, the user stays.
        $t->pdo->exec("UPDATE library_keys SET library = 'read', library_group = 'Site-team' WHERE name = 'Site laptop'");
        $now = $t->get('/', $laptop)->data();
        same([false, 'Site-team', 'Site_laptop'], [$now['canWrite'], $now['group'], $now['user']]);
        same(403, $t->postJson('/cpt', $laptop, ['name' => 'Site B S2', 'content' => $gef])->status, 'a reader may not upload');

        // Deleted in StructuralDocs, or revoked here: refused.
        $t->keys->revoke('Tablet');
        same('key_revoked', $t->get('/', $reader)->data()['reason']);
        $t->pdo->exec("DELETE FROM library_keys WHERE name = 'Site laptop'");
        same('unknown_key', $t->get('/', $laptop)->data()['reason']);
    },

    'a key\'s name the library already has is not taken over; the setting switches these keys too (1.13.0)' => function (): void {
        $t = new TestApp(['structuraldocs' => ['enabled' => true]]);
        structuraldocs_tables($t->pdo);
        structuraldocs_library_keys($t->pdo);
        $own = $t->keys->create('Tablet');
        $key = structuraldocs_library_key($t->pdo, 'Tablet', 'read', 'Site-team');
        $name = $t->get('/', $key)->data()['user'];
        check(str_starts_with($name, 'Tablet-sdk'), "a name of its own, got {$name}");
        same('Tablet', $t->get('/', $own)->data()['user']);

        $off = new TestApp();
        structuraldocs_tables($off->pdo);
        structuraldocs_library_keys($off->pdo);
        $key = structuraldocs_library_key($off->pdo, 'Tablet', 'write', 'Office');
        same('structuraldocs_off', $off->get('/', $key)->data()['reason']);
        same(0, (int)$off->pdo->query('SELECT COUNT(*) FROM api_users')->fetchColumn());
    },
];
