<?php
declare(strict_types=1);
namespace App\Application\Sales\Command;
use Domains\Sales\Application\Service\SalesScheduledActivityService;
use Kernel\Application\Command\CommandHandlerInterface;
final readonly class ManageScheduledSalesActivityCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesScheduledActivityService $activities){}
    public function __invoke(ManageScheduledSalesActivityCommand $c):array
    {return $this->activities->execute($c->organizationId->value(),$c->actorId,$c->operation,$c->input,$c->idempotencyKey,$c->correlationId);}
}
