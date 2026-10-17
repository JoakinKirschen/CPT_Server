<?php
/*
 * API key administration (one key per user).
 *
 *   php bin/keys.php create <name> [--read-only]   new user; prints the key once
 *   php bin/keys.php rotate <name>                 new key; the old one stops working
 *   php bin/keys.php revoke <name>                 key stops working
 *   php bin/keys.php list                          users, without keys
 */

declare(strict_types=1);

require __DIR__ . '/cli.php';

$command = $argv[1] ?? '';
$name = $argv[2] ?? '';

try {
    [$config, $pdo, $clock] = cli_context();
    $keys = new \CptLibrary\Auth\ApiKeys($pdo, (string)$config->get('api.key_pepper'), $clock);
    switch ($command) {
        case 'create':
            $key = $keys->create($name, !in_array('--read-only', $argv, true));
            echo "User {$name} created. Their API key (shown only now):\n\n  {$key}\n\n";
            break;
        case 'rotate':
            $key = $keys->rotate($name);
            echo "New API key for {$name} (shown only now; the old key no longer works):\n\n  {$key}\n\n";
            break;
        case 'revoke':
            $keys->revoke($name);
            echo "The key of {$name} is revoked.\n";
            break;
        case 'list':
            printf("%-24s %-6s %-5s %-19s %-19s %s\n", 'user', 'hint', 'write', 'key created', 'last used', 'status');
            foreach ($keys->list() as $row) {
                printf("%-24s %-6s %-5s %-19s %-19s %s\n", $row['name'], $row['key_hint'] ? '…' . $row['key_hint'] : '', $row['can_write'] ? 'yes' : 'no',
                    $row['key_created_at'] ?? '', $row['last_used_at'] ?? '', $row['revoked_at'] ? 'revoked ' . $row['revoked_at'] : 'active');
            }
            break;
        default:
            cli_fail("Usage: php bin/keys.php create <name> [--read-only] | rotate <name> | revoke <name> | list");
    }
} catch (\Throwable $e) {
    cli_fail($e->getMessage());
}
