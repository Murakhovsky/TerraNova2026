<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\System\Contract\DependencyHealthCheckInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class DependencyHealthController
{
    public function __construct(private DependencyHealthCheckInterface $health)
    {
    }

    public function __invoke(): JsonResponse
    {
        $dependencies = $this->health->check();
        $ok = ($dependencies['status'] ?? null) === 'ok';

        return new JsonResponse([
            'status' => $ok ? 'ok' : 'unavailable',
            'service' => 'cos-symfony',
            'dependencies' => [
                'canonical_mysql' => $dependencies['canonical_mysql'] ?? 'unavailable',
                'legacy_mysql' => $dependencies['legacy_mysql'] ?? 'unavailable',
            ],
        ], $ok ? 200 : 503);
    }
}
