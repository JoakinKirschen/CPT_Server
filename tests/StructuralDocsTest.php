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
