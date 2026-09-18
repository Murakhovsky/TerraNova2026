<?php
declare(strict_types=1);

namespace App\Application\RealEstate\Query;

use Domains\RealEstate\Application\Contract\RealEstateRepositoryInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetRealEstateCaseQueryHandler implements QueryHandlerInterface
{
    public function __construct(private RealEstateRepositoryInterface $repository){}
    public function __invoke(GetRealEstateCaseQuery $query): ?array
    {
        return $this->repository->view($query->organizationId->value(),$query->caseId);
    }
}
