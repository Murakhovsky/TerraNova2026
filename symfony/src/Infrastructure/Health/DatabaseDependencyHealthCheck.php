<?php
declare(strict_types=1);

namespace App\Infrastructure\Health;

use App\Application\System\Contract\DependencyHealthCheckInterface;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class DatabaseDependencyHealthCheck implements DependencyHealthCheckInterface
{
    public function __construct(private Connection $canonical) {}

    public function check(): array
    {
        $database=$this->probe()?'ok':'unavailable';
        return [
            'status'=>$database,
            'canonical_mysql'=>$database,
        ];
    }

    private function probe(): bool
    {
        try {
            return (int)$this->canonical->executeQuery('SELECT 1')->fetchOne()===1;
        } catch (Throwable) {
            return false;
        }
    }
}
