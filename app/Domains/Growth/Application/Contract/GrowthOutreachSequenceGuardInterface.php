<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use DateTimeImmutable;

interface GrowthOutreachSequenceGuardInterface
{
    /** @param array<string,mixed> $recommendation @return array{code:string,reason:string}|null */
    public function bootstrapBlock(string $organizationId,array $recommendation,DateTimeImmutable $startedAt):?array;

    /** @param array<string,mixed> $recommendation @return array{code:string,reason:string}|null */
    public function hardBlockForRecommendation(string $organizationId,array $recommendation):?array;

    /** @param array<string,mixed> $recommendation @return array{code:string,reason:string}|null */
    public function blockingForRecommendation(string $organizationId,array $recommendation):?array;
}
