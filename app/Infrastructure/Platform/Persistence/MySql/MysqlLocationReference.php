<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Persistence\MySql;

use Domains\Property\Application\Contract\LocationReferenceInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

final readonly class MysqlLocationReference implements LocationReferenceInterface
{
    public function __construct(private PdoConnection $database) {}

    public function resolveOrCreate(string $city, ?string $region = null, ?string $district = null): int
    {
        $city = mb_substr(trim($city), 0, 120);
        $slug = $this->slug($city);
        $pdo = $this->database->connection();
        $statement = $pdo->prepare('SELECT id FROM tn_locations WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $id = $statement->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $insert = $pdo->prepare('
            INSERT INTO tn_locations (country_code, region, city, district, slug, is_active)
            VALUES ("UA", :region, :city, :district, :slug, 1)
        ');
        $insert->execute([
            'region' => $this->nullable($region),
            'city' => $city,
            'district' => $this->nullable($district),
            'slug' => $slug,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', '-', $value) ?? '';
        return trim($value, '-') ?: 'location-' . bin2hex(random_bytes(4));
    }

    private function nullable(?string $value): ?string
    {
        $value = mb_substr(trim((string) $value), 0, 120);
        return $value === '' ? null : $value;
    }
}
