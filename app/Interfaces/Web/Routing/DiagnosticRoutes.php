<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

/**
 * Legacy Web host owns only the server-rendered Diagnostic report.
 * Diagnostic business HTTP API is canonical in Symfony /api/v1/diagnostics/*.
 */
final class DiagnosticRoutes
{
    public static function register(RouterInterface $router): void
    {
        $web = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Web\\Controller',
            'module' => 'frontend',
            'controller' => 'diagnostic_report',
            'action' => $action,
        ];

        $session = '[A-Za-z0-9_.:-]{8,64}';
        $router->addGet('/diagnostics/{session:' . $session . '}/report', $web('show'));
    }
}
