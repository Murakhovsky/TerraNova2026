<?php
declare(strict_types=1);

namespace Interfaces\Web\Controller;

final class ErrorController extends ControllerBase
{
    public function notFoundAction(): void
    {
        $path = $this->requestPath();

        if ($path === '/api' || str_starts_with($path, '/api/') || str_starts_with($path, '/webhooks/')) {
            $this->json([
                'ok' => false,
                'error' => 'not_found',
                'message' => 'Маршрут не знайдено.',
            ], 404);
            return;
        }

        $this->renderFrontendFailure(404, null, $this->surfaceFor($path));
    }

    private function requestPath(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        return '/' . ltrim($path, '/');
    }

    private function surfaceFor(string $path): string
    {
        if (preg_match('#^/cabinet(/|$)#', $path) === 1) {
            return 'portal';
        }

        if (
            preg_match('#^/(admin|client-case|sales)(/|$)#', $path) === 1
            || preg_match('#^/cos/control-center(/|$)#', $path) === 1
            || preg_match('#^/property/(manage|listing|edit|add|submissions|submission|group|moderate|media|note)(/|$)#', $path) === 1
            || preg_match('#^/spatial/(manage|edit|save|upload|external|capture|hotspot|publish)(/|$)#', $path) === 1
        ) {
            return 'workspace';
        }

        return 'public';
    }
}
