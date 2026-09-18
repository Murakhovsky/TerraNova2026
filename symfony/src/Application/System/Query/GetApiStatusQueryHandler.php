<?php
declare(strict_types=1);

namespace App\Application\System\Query;

use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetApiStatusQueryHandler implements QueryHandlerInterface
{
    /** @return array{status:string,api_version:string,runtime:string} */
    public function __invoke(GetApiStatusQuery $query): array
    {
        return [
            'status' => 'ok',
            'api_version' => 'v1',
            'runtime' => 'symfony',
        ];
    }
}
