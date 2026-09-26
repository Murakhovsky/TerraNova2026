<?php
declare(strict_types=1);

namespace App\Application\Content\Query;

use App\Application\Content\Service\PublicCosCatalog;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicCosLandingQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PublicCosCatalog $catalog) {}

    /** @return array<string,mixed> */
    public function __invoke(GetPublicCosLandingQuery $query): array
    {
        return $this->catalog->landing($query->lang);
    }
}
