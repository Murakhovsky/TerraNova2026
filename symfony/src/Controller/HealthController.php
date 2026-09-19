<?php
declare(strict_types=1);

namespace App\Controller;

use Kernel\Operations\Contract\OperationsReadModelInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Throwable;

final readonly class HealthController
{
    public function __construct(private OperationsReadModelInterface $operations) {}

    public function index(): JsonResponse
    {
        return new JsonResponse([
            'service' => 'cos-symfony',
            'status' => 'ready',
            'framework' => 'Symfony 7.4 LTS',
        ]);
    }

    public function health(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok', 'service' => 'cos-symfony']);
    }

    public function operations(): JsonResponse
    {
        try {
            $health = $this->operations->health();
            return new JsonResponse($health, ($health['status'] ?? 'down') === 'ok' ? 200 : 503);
        } catch (Throwable) {
            return new JsonResponse(['status' => 'down', 'database' => false], 503);
        }
    }
}
