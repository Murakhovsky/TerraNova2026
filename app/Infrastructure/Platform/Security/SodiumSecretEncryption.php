<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Security;

use Platform\Settings\Contract\SecretEncryptionInterface;
use RuntimeException;

final readonly class SodiumSecretEncryption implements SecretEncryptionInterface
{
    private const VERSION = 1;

    public function __construct(
        private string $masterKey,
        private string $keyId = 'env-v1',
    ) {}

    public function encrypt(string $plaintext, string $associatedData): array
    {
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $associatedData,
            $nonce,
            $key,
        );

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce' => base64_encode($nonce),
            'encryption_version' => self::VERSION,
            'key_id' => $this->keyId,
        ];
    }

    public function decrypt(string $ciphertext, string $nonce, int $encryptionVersion, string $keyId, string $associatedData): string
    {
        if ($encryptionVersion !== self::VERSION) throw new RuntimeException('Unsupported secret encryption version.');
        if ($keyId !== $this->keyId) throw new RuntimeException('Secret key id does not match active master key.');

        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            base64_decode($ciphertext, true) ?: throw new RuntimeException('Invalid encrypted secret payload.'),
            $associatedData,
            base64_decode($nonce, true) ?: throw new RuntimeException('Invalid encrypted secret nonce.'),
            $this->key(),
        );
        if ($plain === false) throw new RuntimeException('Secret authentication failed.');
        return $plain;
    }

    private function key(): string
    {
        $raw = trim($this->masterKey);
        if ($raw === '') throw new RuntimeException('COS_SECRET_MASTER_KEY is not configured.');

        if (preg_match('/^[a-f0-9]{64}$/i', $raw) === 1) {
            $hex = hex2bin($raw);
            if ($hex !== false) return $hex;
        }

        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            return $decoded;
        }

        if (strlen($raw) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            return $raw;
        }

        throw new RuntimeException('COS_SECRET_MASTER_KEY must be a 32-byte raw, base64, or 64-character hex key.');
    }
}
