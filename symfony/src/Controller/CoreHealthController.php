<?php

declare(strict_types=1);

namespace App\Controller;

use Kernel\Operations\Contract\OperationsReadModelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

final class CoreHealthController
{
    public function __construct(
        private readonly OperationsReadModelInterface $operations,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        try {
            $health = $this->operations->health();

            return new JsonResponse([
                'status' => 'connected',
                'service' => 'cos-symfony',
                'source' => 'legacy-cos-read-model',
                'read_model' => $this->operations::class,
                'legacy' => $health,
            ]);
        } catch (Throwable) {
            return new JsonResponse([
                'status' => 'unavailable',
                'service' => 'cos-symfony',
                'source' => 'legacy-cos-read-model',
            ], 503);
        }
    }
}
