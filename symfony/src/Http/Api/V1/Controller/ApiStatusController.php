<?php
declare(strict_types=1);

namespace App\Http\Api\V1\Controller;

use App\Application\System\Query\GetApiStatusQuery;
use Kernel\Application\Bus\QueryBusInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class ApiStatusController
{
    public function __construct(private QueryBusInterface $queries)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'data' => $this->queries->ask(new GetApiStatusQuery()),
        ]);
    }
}
