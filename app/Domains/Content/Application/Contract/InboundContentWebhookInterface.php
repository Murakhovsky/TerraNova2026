<?php
declare(strict_types=1);

namespace Domains\Content\Application\Contract;

interface InboundContentWebhookInterface
{
    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function handle(
        string $rawBody,
        string $signature,
        string $timestamp,
        string $idempotencyKey
    ): array;
}
