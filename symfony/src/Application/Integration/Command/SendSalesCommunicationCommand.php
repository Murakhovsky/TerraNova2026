<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use Kernel\Application\Command\CommandInterface;
use Kernel\Shared\Domain\OrganizationId;

final readonly class SendSalesCommunicationCommand implements CommandInterface
{
    public const SUPPORTED_CHANNELS = ['TELEGRAM', 'EMAIL', 'PHONE', 'WEB', 'WHATSAPP', 'VIBER'];

    public function __construct(
        public OrganizationId $organizationId,
        public int $actorId,
        public int $opportunityId,
        public string $channel,
        public string $body,
        public string $idempotencyKey,
        public string $correlationId,
    ) {
    }
}
