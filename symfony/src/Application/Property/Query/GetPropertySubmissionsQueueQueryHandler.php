<?php

declare(strict_types=1);

namespace App\Application\Property\Query;

use Domains\Property\Application\Contract\PropertyWorkspaceReadModelInterface;
use Kernel\Application\Query\QueryHandlerInterface;

final readonly class GetPropertySubmissionsQueueQueryHandler implements QueryHandlerInterface
{
    public function __construct(private PropertyWorkspaceReadModelInterface $workspace)
    {
    }

    /** @return array<string,mixed> */
    public function __invoke(GetPropertySubmissionsQueueQuery $query): array
    {
        $page=max(1,$query->page);
        $perPage=max(10,min(100,$query->perPage));
        $offset=($page-1)*$perPage;

        $data=$this->workspace->submissions(
            $query->organizationId->value(),
            trim($query->status),
            $perPage,
            $offset,
        );
        $data['pagination']=[
            'page'=>$page,
            'per_page'=>$perPage,
            'total'=>(int)($data['total']??0),
        ];

        return $data;
    }
}
