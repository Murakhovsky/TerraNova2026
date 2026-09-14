<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql;

use Domains\Property\Application\Contract\PropertySubmissionRepositoryInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

final readonly class MysqlPropertySubmissionRepository implements PropertySubmissionRepositoryInterface
{
    public function __construct(private PdoConnection $database)
    {
    }

    public function create(array $submission): int
    {
        $statement = $this->database->connection()->prepare('
            INSERT INTO tn_property_submissions (
                submission_ref, status, source_type, deal_type, property_type, title,
                city, region, district, address, price_amount, price_currency,
                area_total, land_area, rooms, floor, floors, built_year, has_3d_tour,
                media_links, description, features_text, owner_name, owner_phone,
                owner_email, preferred_contact, source_page
            ) VALUES (
                :submission_ref, "new", :source_type, :deal_type, :property_type, :title,
                :city, :region, :district, :address, :price_amount, :price_currency,
                :area_total, :land_area, :rooms, :floor, :floors, :built_year, :has_3d_tour,
                :media_links, :description, :features_text, :owner_name, :owner_phone,
                :owner_email, :preferred_contact, :source_page
            )
        ');
        $statement->execute($submission);

        return (int) $this->database->connection()->lastInsertId();
    }

    public function findForOwner(int $id, string $email): ?array
    {
        return $this->database->fetchOne('
            SELECT s.*, p.slug AS property_slug, p.title AS property_title
            FROM tn_property_submissions s
            LEFT JOIN tn_properties p ON p.id = s.property_id
            WHERE s.id = :id
              AND LOWER(s.owner_email) = :email
            LIMIT 1
        ', ['id' => $id, 'email' => $email]);
    }

    public function update(int $id, array $submission): void
    {
        $this->database->connection()->prepare('
            UPDATE tn_property_submissions
            SET status = "submitted",
                source_type = :source_type,
                deal_type = :deal_type,
                property_type = :property_type,
                title = :title,
                city = :city,
                region = :region,
                district = :district,
                address = :address,
                price_amount = :price_amount,
                price_currency = :price_currency,
                area_total = :area_total,
                land_area = :land_area,
                rooms = :rooms,
                floor = :floor,
                floors = :floors,
                built_year = :built_year,
                has_3d_tour = :has_3d_tour,
                media_links = :media_links,
                description = :description,
                features_text = :features_text,
                owner_name = :owner_name,
                owner_phone = :owner_phone,
                owner_email = :owner_email,
                preferred_contact = :preferred_contact,
                reviewed_at = NULL,
                review_note = NULL,
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ')->execute(['id' => $id] + $submission);
    }

}
