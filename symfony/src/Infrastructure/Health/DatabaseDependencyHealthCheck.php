<?php
declare(strict_types=1);

namespace App\Infrastructure\Health;

use App\Application\System\Contract\DependencyHealthCheckInterface;
use Doctrine\DBAL\Connection;
use PDO;
use Throwable;

final readonly class DatabaseDependencyHealthCheck implements DependencyHealthCheckInterface
{
    public function __construct(
        private Connection $canonical,
        private PDO $legacy,
    ) {
    }

    public function check(): array
    {
        $canonical = $this->probeCanonical() ? 'ok' : 'unavailable';
        $legacy = $this->probeLegacy() ? 'ok' : 'unavailable';

        return [
            'status' => $canonical === 'ok' && $legacy === 'ok' ? 'ok' : 'unavailable',
            'canonical_mysql' => $canonical,
            'legacy_mysql' => $legacy,
        ];
    }

    private function probeCanonical(): bool
    {
        try {
            return (int) $this->canonical->executeQuery('SELECT 1')->fetchOne() === 1;
        } catch (Throwable) {
            return false;
        }
    }

    private function probeLegacy(): bool
    {
        try {
            return (int) $this->legacy->query('SELECT 1')->fetchColumn() === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
