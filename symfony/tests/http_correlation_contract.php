<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Observability\HttpExecutionContextSubscriber;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Kernel\Tenant\Model\TenantContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

function expectCorrelation(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$logger = new class implements StructuredLoggerInterface {
    public array $entries = [];
    public function log(string $level, string $message, array $context = []): void
    {
        $this->entries[] = compact('level', 'message', 'context');
    }
};
$tenants = new class implements TenantContextProviderInterface {
    public function current(): ?TenantContext { return null; }
};
$kernel = new class implements HttpKernelInterface {
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
    {
        return new Response();
    }
};

$subscriber = new HttpExecutionContextSubscriber($logger, $tenants);
$request = Request::create('/api/v1/status');
$request->headers->set('X-Correlation-ID', 'client-corr-1');
$subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

$response = new Response('', 403);
$subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));

expectCorrelation($response->headers->get('X-Correlation-ID') === 'client-corr-1', 'Valid inbound correlation id must be preserved.');
expectCorrelation(($logger->entries[0]['message'] ?? null) === 'http.request.completed', 'HTTP completion must emit a structured log.');
expectCorrelation(($logger->entries[0]['context']['correlation_id'] ?? null) === 'client-corr-1', 'Structured HTTP log must contain correlation id.');
expectCorrelation(($logger->entries[0]['context']['status_code'] ?? null) === 403, 'Structured HTTP log must contain response status.');

$invalidRequest = Request::create('/health');
$invalidRequest->headers->set('X-Correlation-ID', '../bad correlation');
$subscriber->onRequest(new RequestEvent($kernel, $invalidRequest, HttpKernelInterface::MAIN_REQUEST));
$invalidResponse = new Response('', 200);
$subscriber->onResponse(new ResponseEvent($kernel, $invalidRequest, HttpKernelInterface::MAIN_REQUEST, $invalidResponse));
$replacement = (string) $invalidResponse->headers->get('X-Correlation-ID');
expectCorrelation((bool) preg_match('/^[a-f0-9]{32}$/', $replacement), 'Invalid inbound correlation id must be replaced safely.');

echo "Symfony HTTP correlation contract passed.\n";
