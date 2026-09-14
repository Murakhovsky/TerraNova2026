<?php
declare(strict_types=1);

namespace Domains\Sales\Application\DTO;

final readonly class SendMessageCommand
{
    public function __construct(
        public string $organizationId,
        public string $dealReference,
        public string $channel,
        public string $body,
        public string $idempotencyKey,
    ) {
    }
}
