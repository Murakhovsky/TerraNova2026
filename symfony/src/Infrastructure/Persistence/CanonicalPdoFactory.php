<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use PDO;
use RuntimeException;

final class CanonicalPdoFactory
{
    public function create(Connection $connection): PDO
    {
        $native = $connection->getNativeConnection();
        if (!$native instanceof PDO) {
            throw new RuntimeException('Canonical Doctrine connection is not backed by PDO.');
        }

        $native->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $native->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $native->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        return $native;
    }
}
