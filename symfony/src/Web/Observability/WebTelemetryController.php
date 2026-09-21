<?php

declare(strict_types=1);

namespace App\Web\Observability;

use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use Throwable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class WebTelemetryController
{
    private const ALLOWED_TYPES = ['error', 'unhandledrejection', 'navigation', 'resource'];

    public function __construct(
        private StructuredLoggerInterface $logger,
        private MetricsRecorderInterface $metrics,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_json'], Response::HTTP_BAD_REQUEST);
        }

        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return new JsonResponse(['ok' => false, 'error' => 'unsupported_type'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $path = $this->boundedPath((string) ($payload['path'] ?? '/'));
        $surface = $this->surface((string) ($payload['surface'] ?? 'web'));
        $durationMs = $this->boundedNumber($payload['duration_ms'] ?? null, 0.0, 120000.0);
        $value = $this->boundedNumber($payload['value'] ?? null, 0.0, 100000000.0);

        $context = [
            'type' => $type,
            'surface' => $surface,
            'path' => $path,
            'message' => $this->boundedText((string) ($payload['message'] ?? ''), 500),
            'source' => $this->boundedText((string) ($payload['source'] ?? ''), 240),
            'duration_ms' => $durationMs,
            'value' => $value,
            'user_agent' => $this->boundedText((string) $request->headers->get('User-Agent', ''), 240),
        ];

        try {
            $this->logger->log($type === 'error' || $type === 'unhandledrejection' ? 'warning' : 'info', 'web.telemetry.' . $type, $context);

            $labels = ['type' => $type, 'surface' => $surface];
            $this->metrics->record('cos.web.telemetry.events', 1.0, null, $labels);

            if ($durationMs !== null) {
                $this->metrics->record('cos.web.telemetry.duration_ms', $durationMs, null, $labels);
            }

            if ($value !== null) {
                $this->metrics->record('cos.web.telemetry.value', $value, null, $labels);
            }
        } catch (Throwable) {
            // Browser telemetry is diagnostic-only. Storage failure must never affect the page.
        }

        return new Response('', Response::HTTP_NO_CONTENT, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function boundedText(string $value, int $limit): string
    {
        $value = trim($value);
        return mb_substr($value, 0, $limit);
    }

    private function boundedPath(string $value): string
    {
        $path = parse_url($value, PHP_URL_PATH);
        return $this->boundedText(is_string($path) && $path !== '' ? $path : '/', 240);
    }

    private function surface(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['public', 'workspace', 'portal', 'web'], true) ? $value : 'web';
    }

    private function boundedNumber(mixed $value, float $min, float $max): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;
        return max($min, min($max, $number));
    }
}
