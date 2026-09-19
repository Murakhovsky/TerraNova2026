<?php
declare(strict_types=1);

namespace App\Application\Service\Query;

use Domains\Service\Application\Contract\ServiceApplicationBoundary;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetServiceTicketQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ServiceApplicationBoundary $service) {}
    public function __invoke(GetServiceTicketQuery $query):?array
    {
        return $this->service->viewTicket($query->organizationId->value(),$query->ticketId);
    }
}
