<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Web\Observability\WebTelemetryController;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use Symfony\Component\HttpFoundation\Request;

function expectTelemetry(bool $condition, string $message): void
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

$metrics = new class implements MetricsRecorderInterface {
    public array $records = [];
    public function record(string $metric, float $value, ?string $organizationId = null, array $labels = []): void
    {
        $this->records[] = compact('metric', 'value', 'organizationId', 'labels');
    }
};

$controller = new WebTelemetryController($logger, $metrics);
$request = Request::create('/telemetry/web', 'POST', [], [], [], [], json_encode([
    'type' => 'error',
    'surface' => 'public',
    'path' => '/property/catalog?secret=drop-me',
    'message' => str_repeat('x', 900),
    'source' => 'https://example.test/app.js?token=drop-me',
], JSON_THROW_ON_ERROR));

$response = $controller($request);
expectTelemetry($response->getStatusCode() === 204, 'Valid telemetry event must return 204.');
expectTelemetry(($logger->entries[0]['message'] ?? null) === 'web.telemetry.error', 'Telemetry must emit structured event.');
expectTelemetry(($logger->entries[0]['context']['path'] ?? null) === '/property/catalog', 'Telemetry must strip query strings from paths.');
expectTelemetry(strlen((string) ($logger->entries[0]['context']['message'] ?? '')) <= 500, 'Telemetry message must be bounded.');
expectTelemetry(($metrics->records[0]['metric'] ?? null) === 'cos.web.telemetry.events', 'Telemetry must record event metric.');
expectTelemetry(!isset($metrics->records[0]['labels']['path']), 'Telemetry metrics must avoid high-cardinality path labels.');

$invalid = $controller(Request::create('/telemetry/web', 'POST', [], [], [], [], '{"type":"arbitrary"}'));
expectTelemetry($invalid->getStatusCode() === 422, 'Unsupported telemetry event types must be rejected.');

echo "Wave 12.24 web telemetry contract passed.\n";
