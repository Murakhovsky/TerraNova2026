<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql;

use Domains\Spatial\Application\Contract\PropertyTourPublisherInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

final readonly class MysqlPropertyTourPublisher implements PropertyTourPublisherInterface
{
    public function __construct(private PdoConnection $database) {}

    public function publishTour(int $propertyId, string $url): void
    {
        $this->database->connection()
            ->prepare('UPDATE tn_properties SET has_3d_tour = 1, tour_url = :url WHERE id = :id')
            ->execute(['url' => $url, 'id' => $propertyId]);
    }
}
