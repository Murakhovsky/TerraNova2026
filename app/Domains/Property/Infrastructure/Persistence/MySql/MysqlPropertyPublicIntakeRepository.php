<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\Persistence\MySql;

use Domains\Property\Application\Contract\PropertyPublicIntakeRepositoryInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;
use InvalidArgumentException;

final readonly class MysqlPropertyPublicIntakeRepository implements PropertyPublicIntakeRepositoryInterface
{
    public function __construct(private PdoConnection $database) {}

    public function create(string $organizationId, array $submission): int
    {
        $organizationId=trim($organizationId);
        if($organizationId==='') throw new InvalidArgumentException('Property public intake requires organization scope.');

        $statement=$this->database->connection()->prepare('
            INSERT INTO tn_property_submissions (
                organization_id, submission_ref, status, source_type, deal_type, property_type, title,
                city, region, district, address, price_amount, price_currency,
                area_total, land_area, rooms, floor, floors, built_year, has_3d_tour,
                media_links, description, features_text, owner_name, owner_phone,
                owner_email, preferred_contact, source_page
            ) VALUES (
                :organization_id, :submission_ref, "new", :source_type, :deal_type, :property_type, :title,
                :city, :region, :district, :address, :price_amount, :price_currency,
                :area_total, :land_area, :rooms, :floor, :floors, :built_year, :has_3d_tour,
                :media_links, :description, :features_text, :owner_name, :owner_phone,
                :owner_email, :preferred_contact, :source_page
            )
        ');
        $statement->execute(['organization_id'=>$organizationId]+$submission);

        return (int)$this->database->connection()->lastInsertId();
    }
}
