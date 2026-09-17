<?php
declare(strict_types=1);

namespace Platform\Integration\Contract;

use Platform\Integration\Model\Credential;

interface CredentialVaultInterface
{
    /** @return array<string,string> Secret material must never be persisted in Credential itself. */
    public function resolve(Credential $credential): array;
}
