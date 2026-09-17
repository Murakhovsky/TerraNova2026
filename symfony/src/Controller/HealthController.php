<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

final class HealthController
{
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'service' => 'cos-symfony',
            'status' => 'migration-runtime-ready',
            'framework' => 'Symfony 7.4 LTS',
        ]);
    }

    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => 'cos-symfony',
        ]);
    }
}
