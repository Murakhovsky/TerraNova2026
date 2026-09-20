<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application\System\Contract\DependencyHealthCheckInterface;
use App\Controller\DependencyHealthController;

function expectDependencyHealth(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$healthy = new class implements DependencyHealthCheckInterface {
    public function check(): array
    {
        return ['status' => 'ok', 'canonical_mysql' => 'ok', 'legacy_mysql' => 'ok'];
    }
};

$response = (new DependencyHealthController($healthy))();
$payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
expectDependencyHealth($response->getStatusCode() === 200, 'Healthy database dependencies must return HTTP 200.');
expectDependencyHealth(($payload['dependencies']['canonical_mysql'] ?? null) === 'ok', 'Canonical MySQL status must be exposed.');
expectDependencyHealth(($payload['dependencies']['legacy_mysql'] ?? null) === 'ok', 'Legacy MySQL transition status must be exposed.');

$degraded = new class implements DependencyHealthCheckInterface {
    public function check(): array
    {
        return ['status' => 'unavailable', 'canonical_mysql' => 'ok', 'legacy_mysql' => 'unavailable'];
    }
};

$response = (new DependencyHealthController($degraded))();
expectDependencyHealth($response->getStatusCode() === 503, 'Unavailable transition dependency must return HTTP 503.');

echo "Dependency health contract passed.\n";
