<?php
declare(strict_types=1);

namespace Infrastructure\Integration\Crm;

use Domains\Sales\Application\Contract\SalesIntegrationHealthProbeInterface;
use Throwable;

final readonly class CrmRegistrySalesIntegrationHealthProbe implements SalesIntegrationHealthProbeInterface
{
    public function __construct(private CrmRegistry $registry)
    {
    }

    public function probe(string $capability, string $provider, array $config, ?string $credentialsReference): array
    {
        if (strtoupper($capability) !== 'CRM') {
            return ['status' => 'UNKNOWN', 'reason' => 'No health probe is registered for this integration capability.'];
        }

        try {
            $this->registry->get($provider);
        } catch (Throwable $error) {
            return ['status' => 'ERROR', 'reason' => $this->sanitize($error->getMessage())];
        }

        return ['status' => 'HEALTHY', 'reason' => null];
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/(token|secret|password|key)\s*[=:]\s*\S+/i', '$1=[redacted]', $message) ?? $message;
        return mb_substr(trim($message), 0, 500);
    }
}
