<?php
declare(strict_types=1);

namespace Platform\Settings\Contract;

interface SecretEncryptionInterface
{
    /** @return array{ciphertext:string,nonce:string,encryption_version:int,key_id:string} */
    public function encrypt(string $plaintext, string $associatedData): array;
    public function decrypt(string $ciphertext, string $nonce, int $encryptionVersion, string $keyId, string $associatedData): string;
}
