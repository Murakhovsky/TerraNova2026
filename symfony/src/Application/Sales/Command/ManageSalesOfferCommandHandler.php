<?php
declare(strict_types=1);
namespace App\Application\Sales\Command;

use Domains\Sales\Application\Service\SalesOfferService;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ManageSalesOfferCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesOfferService $offers){}
    public function __invoke(ManageSalesOfferCommand $command):array
    {
        return $this->offers->execute($command->organizationId->value(),$command->actorId,$command->operation,
            $command->input,$command->idempotencyKey,$command->correlationId);
    }
}
