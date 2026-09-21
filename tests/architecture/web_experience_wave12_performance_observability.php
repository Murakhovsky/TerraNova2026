<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

function expectWave1224(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$subscriber = file_get_contents($root . '/symfony/src/Infrastructure/Observability/HttpExecutionContextSubscriber.php');
$controller = file_get_contents($root . '/symfony/src/Web/Observability/WebTelemetryController.php');
$routes = file_get_contents($root . '/symfony/config/routes.yaml');
$rateLimit = file_get_contents($root . '/symfony/src/Security/RequestRateLimitSubscriber.php');
$publicRuntime = file_get_contents($root . '/frontend/core/telemetry.js');
$symfonyRuntime = file_get_contents($root . '/symfony/assets/web_telemetry.js');
$budget = file_get_contents($root . '/tests/browser/web_performance_budget.mjs');

expectWave1224(str_contains($subscriber, 'MetricsRecorderInterface'), 'HTTP observability must reuse canonical MetricsRecorderInterface.');
expectWave1224(str_contains($subscriber, 'Server-Timing'), 'HTTP responses must expose Server-Timing.');
expectWave1224(str_contains($subscriber, 'cos.web.http.duration_ms'), 'HTTP duration metric is missing.');
expectWave1224(str_contains($subscriber, 'cos.web.http.errors'), 'HTTP error metric is missing.');
expectWave1224(str_contains($controller, 'cos.web.telemetry.events'), 'Browser telemetry must use canonical metrics.');
expectWave1224(str_contains($routes, 'path: /telemetry/web'), 'Browser telemetry route is missing.');
expectWave1224(str_contains($rateLimit, "['web.telemetry', 180, 60]"), 'Browser telemetry must be rate limited.');
expectWave1224(str_contains($publicRuntime, "addEventListener('error'"), 'Public runtime must capture browser errors.');
expectWave1224(str_contains($publicRuntime, "addEventListener('unhandledrejection'"), 'Public runtime must capture unhandled rejections.');
expectWave1224(str_contains($symfonyRuntime, 'performance.getEntriesByType'), 'Symfony runtime must emit navigation telemetry.');
expectWave1224(str_contains($budget, 'transferBytes'), 'Performance budget must cover transfer size.');
expectWave1224(str_contains($budget, 'ttfbMs'), 'Performance budget must cover TTFB.');
expectWave1224(str_contains($budget, 'domNodes'), 'Performance budget must cover DOM size.');

echo "Wave 12.24 Performance & Observability architecture gate passed.\n";
