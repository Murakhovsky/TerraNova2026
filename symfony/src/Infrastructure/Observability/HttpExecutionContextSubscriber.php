<?php
declare(strict_types=1);

namespace App\Infrastructure\Observability;

use InvalidArgumentException;
use Kernel\Observability\CorrelationId;
use Kernel\Observability\ExecutionContext;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use Throwable;
use Kernel\Tenant\Contract\TenantContextProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class HttpExecutionContextSubscriber implements EventSubscriberInterface
{
    private const CORRELATION_ATTRIBUTE = '_cos_correlation_id';
    private const START_ATTRIBUTE = '_cos_started_ns';

    public function __construct(
        private StructuredLoggerInterface $logger,
        private TenantContextProviderInterface $tenants,
        private MetricsRecorderInterface $metrics,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 2048],
            KernelEvents::RESPONSE => ['onResponse', -2048],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $candidate = trim((string) $request->headers->get('X-Correlation-ID', ''));
        try {
            $correlationId = $candidate !== ''
                ? CorrelationId::fromString($candidate)
                : CorrelationId::generate();
        } catch (InvalidArgumentException) {
            $correlationId = CorrelationId::generate();
        }

        $request->attributes->set(self::CORRELATION_ATTRIBUTE, $correlationId);
        $request->attributes->set(self::START_ATTRIBUTE, hrtime(true));
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $correlationId = $request->attributes->get(self::CORRELATION_ATTRIBUTE);
        if (!$correlationId instanceof CorrelationId) {
            $correlationId = CorrelationId::generate();
        }

        $event->getResponse()->headers->set('X-Correlation-ID', $correlationId->value());

        $tenant = $this->tenants->current();
        $context = new ExecutionContext(
            $correlationId,
            'http',
            $tenant?->organizationId(),
            $tenant?->userId(),
        );

        $started = $request->attributes->get(self::START_ATTRIBUTE);
        $durationMs = is_int($started) ? max(0.0, (hrtime(true) - $started) / 1_000_000) : null;
        $statusCode = $event->getResponse()->getStatusCode();
        $route = (string) $request->attributes->get('_route', 'unmatched');
        $method = strtoupper($request->getMethod());
        $statusClass = intdiv($statusCode, 100) . 'xx';

        if ($durationMs !== null) {
            $event->getResponse()->headers->set('Server-Timing', sprintf('app;dur=%.2f', $durationMs));
        }

        try {
            $organizationId = $tenant?->organizationId()->value();
            $labels = [
                'method' => $method,
                'route' => $route !== '' ? $route : 'unmatched',
                'status_class' => $statusClass,
            ];

            $this->metrics->record('cos.web.http.requests', 1.0, $organizationId, $labels);
            if ($durationMs !== null) {
                $this->metrics->record('cos.web.http.duration_ms', $durationMs, $organizationId, $labels);
            }
            if ($statusCode >= 400) {
                $this->metrics->record('cos.web.http.errors', 1.0, $organizationId, $labels);
            }
        } catch (Throwable $exception) {
            $this->logger->log('warning', 'http.metrics.failed', [
                'correlation_id' => $correlationId->value(),
                'error_class' => $exception::class,
            ]);
        }

        $this->logger->log('info', 'http.request.completed', array_merge($context->toLogContext(), [
            'method' => $method,
            'route' => $route !== '' ? $route : 'unmatched',
            'path' => $request->getPathInfo(),
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
        ]));
    }
}
