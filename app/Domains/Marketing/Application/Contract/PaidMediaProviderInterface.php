<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

/** Read-only provider port. No write methods until canonical Finance/Workflow cutover. */
interface PaidMediaProviderInterface
{
    /** @return array<string,mixed> */
    public function inspectAccount(string $organizationId,string $providerAccountId,string $credentialRef):array;
    /** @return array{campaigns:list<array<string,mixed>>,adsets:list<array<string,mixed>>,ads:list<array<string,mixed>>} */
    public function hierarchy(string $organizationId,string $providerAccountId,string $credentialRef):array;
    /** @return list<array<string,mixed>> */
    public function insights(string $organizationId,string $providerAccountId,string $credentialRef,string $from,string $to,string $grain):array;
}
