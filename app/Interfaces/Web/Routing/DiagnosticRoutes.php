<?php
declare(strict_types=1);

namespace Interfaces\Web\Routing;

use Phalcon\Mvc\RouterInterface;

final class DiagnosticRoutes
{
    public static function register(RouterInterface $router): void
    {
        $api = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Api\\Controller',
            'module' => 'frontend',
            'controller' => 'diagnostic_runtime',
            'action' => $action,
        ];
        $web = static fn (string $action): array => [
            'namespace' => 'Interfaces\\Web\\Controller',
            'module' => 'frontend',
            'controller' => 'diagnostic_report',
            'action' => $action,
        ];

        $session = '[A-Za-z0-9_.:-]{8,64}';
        $recommendation = '[A-Za-z0-9_.:-]{1,128}';

        $router->addPost('/api/diagnostics', $api('start'));
        $router->addGet('/api/diagnostics/{session:' . $session . '}', $api('resume'));
        $router->addGet('/api/diagnostics/{session:' . $session . '}/next', $api('next'));
        $router->addPost('/api/diagnostics/{session:' . $session . '}/answers', $api('answer'));
        $router->addPost('/api/diagnostics/{session:' . $session . '}/complete', $api('complete'));
        $router->addGet('/api/diagnostics/{session:' . $session . '}/report', $api('report'));
        $router->addPost(
            '/api/diagnostics/{session:' . $session . '}/recommendations/{recommendation:' . $recommendation . '}/accept',
            $api('accept'),
        );
        $router->addPost('/api/diagnostics/{session:' . $session . '}/re-diagnostic', $api('reDiagnostic'));
        $router->addGet(
            '/api/diagnostics/{before:' . $session . '}/compare/{after:' . $session . '}',
            $api('compare'),
        );
        $router->addGet('/diagnostics/{session:' . $session . '}/report', $web('show'));
    }
}
