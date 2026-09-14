<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\ReadModel\MySql;

use Domains\Property\Application\Contract\PropertyWorkspaceReadModelInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use InvalidArgumentException;

final readonly class MysqlPropertyWorkspaceReadModel implements PropertyWorkspaceReadModelInterface
{
    public function __construct(private PdoConnection $database)
    {
    }

    /** @return array<string, mixed> */
    public function overview(string $organizationId, int $limit = 6): array
    {
        $organizationId = trim($organizationId);
        if ($organizationId === '') {
            throw new InvalidArgumentException('Organization id is required.');
        }

        $limit = max(1, min(12, $limit));
        $params = ['organization_id' => $organizationId];

        $stats = $this->database->fetchOne('
            SELECT
                COUNT(*) AS total,
                SUM(status = "published") AS published,
                SUM(status = "active") AS active,
                SUM(status = "reserved") AS reserved,
                SUM(status IN ("published", "active")) AS catalog_total
            FROM tn_properties
            WHERE organization_id = :organization_id
        ', $params) ?? [];

        $featured = $this->database->fetchAll('
            SELECT
                p.id, p.public_id, p.slug, p.title, p.status, p.updated_at,
                t.name_uk AS type_name,
                l.city
            FROM tn_properties p
            INNER JOIN tn_property_types t ON t.id = p.type_id
            INNER JOIN tn_locations l ON l.id = p.location_id
            WHERE p.organization_id = :organization_id
              AND p.status IN ("published", "active", "reserved")
            ORDER BY p.is_featured DESC, p.updated_at DESC, p.id DESC
            LIMIT ' . $limit . '
        ', $params);

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'catalog_total' => (int) ($stats['catalog_total'] ?? 0),
            'published' => (int) ($stats['published'] ?? 0),
            'active' => (int) ($stats['active'] ?? 0),
            'reserved' => (int) ($stats['reserved'] ?? 0),
            'featured' => $featured,
        ];
    }
}
