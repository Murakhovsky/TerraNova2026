<?php
declare(strict_types=1);

namespace App\Application\Property\Query;

use Domains\Property\Contract\PropertyReferencePort;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPropertyQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PropertyReferencePort $properties) {}

    public function __invoke(GetPropertyQuery $query): ?array
    {
        return $this->properties->getPropertyPresentation($query->organizationId->value(),$query->reference);
    }
}
