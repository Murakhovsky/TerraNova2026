<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2).'/symfony/src/Engineering/Application/Security/EngineeringSecretIsolationGuard.php';

use App\Engineering\Application\Security\EngineeringSecretIsolationGuard;

$guard = new EngineeringSecretIsolationGuard();
$input = [
    'password' => 'super-secret-password',
    'api_key' => 'sk-abcdefghijklmnopqrstuvwxyz123456',
    'credential_reference' => 'vault://org/integration/api-key',
    'nested' => [
        'authorization' => 'Bearer abcdefghijklmnopqrstuvwxyz',
        'note' => 'token=plaintext-token-value keep working',
        'safe' => 'ordinary context',
    ],
    'pem' => "-----BEGIN PRIVATE KEY-----\nabc123\n-----END PRIVATE KEY-----",
];

$out = $guard->sanitize($input);
if (($out['password'] ?? null) !== '[REDACTED]') throw new RuntimeException('Password reached Engineering Agent context.');
if (($out['api_key'] ?? null) !== '[REDACTED]') throw new RuntimeException('API key reached Engineering Agent context.');
if (($out['credential_reference'] ?? null) !== 'vault://org/integration/api-key') throw new RuntimeException('Secret reference was not preserved.');
if (($out['nested']['authorization'] ?? null) !== '[REDACTED]') throw new RuntimeException('Authorization reached Engineering Agent context.');
if (str_contains((string) ($out['nested']['note'] ?? ''), 'plaintext-token-value')) throw new RuntimeException('Free-text secret assignment was not redacted.');
if (str_contains((string) ($out['pem'] ?? ''), 'abc123')) throw new RuntimeException('Private key material was not redacted.');
if (($out['nested']['safe'] ?? null) !== 'ordinary context') throw new RuntimeException('Secret isolation damaged safe context.');

echo "Engineering secret isolation passed.\n";
