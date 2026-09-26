<?php
declare(strict_types=1);

namespace App\Application\Content\Query;

use App\Application\Content\Service\PublicCosCatalog;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPublicCosDomainQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PublicCosCatalog $catalog) {}

    /** @return array<string,mixed>|null */
    public function __invoke(GetPublicCosDomainQuery $query): ?array
    {
        return $this->catalog->domain($query->lang, $query->slug);
    }
}
