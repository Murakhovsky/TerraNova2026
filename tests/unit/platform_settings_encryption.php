<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Infrastructure\Platform\Security\SodiumSecretEncryption;

$key = base64_encode(str_repeat("K", 32));
$crypto = new SodiumSecretEncryption($key, 'test-v1');

$encrypted = $crypto->encrypt('sk-secret-value', 'org-1|llm|openai.api_key');
if (($encrypted['encryption_version'] ?? null) !== 1) throw new RuntimeException('Encryption version mismatch.');
if (($encrypted['key_id'] ?? null) !== 'test-v1') throw new RuntimeException('Encryption key id mismatch.');
if (($encrypted['ciphertext'] ?? '') === 'sk-secret-value') throw new RuntimeException('Secret was stored in plaintext.');

$plain = $crypto->decrypt(
    $encrypted['ciphertext'],
    $encrypted['nonce'],
    $encrypted['encryption_version'],
    $encrypted['key_id'],
    'org-1|llm|openai.api_key',
);
if ($plain !== 'sk-secret-value') throw new RuntimeException('Encrypted secret did not round-trip.');

try {
    $crypto->decrypt(
        $encrypted['ciphertext'],
        $encrypted['nonce'],
        $encrypted['encryption_version'],
        $encrypted['key_id'],
        'org-2|llm|openai.api_key',
    );
    throw new RuntimeException('Secret decrypted under another tenant context.');
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'Secret decrypted under another tenant context.') throw $error;
}

$hexCrypto = new SodiumSecretEncryption(str_repeat('ab', 32), 'hex-v1');
$hexEncrypted = $hexCrypto->encrypt('hex-secret', 'org-1|llm|openai.api_key');
if ($hexCrypto->decrypt(
    $hexEncrypted['ciphertext'],
    $hexEncrypted['nonce'],
    $hexEncrypted['encryption_version'],
    $hexEncrypted['key_id'],
    'org-1|llm|openai.api_key',
) !== 'hex-secret') {
    throw new RuntimeException('Hex master key did not round-trip.');
}

echo "Platform Settings secret encryption passed.\n";
