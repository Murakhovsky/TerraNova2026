<?php
declare(strict_types=1);

namespace Common\Models\RealEstate;

use Phalcon\Mvc\Model;

class PropertySubmission extends Model
{
    public $id;
    public $submission_ref;
    public $status;
    public $property_id;
    public $source_type;
    public $deal_type;
    public $property_type;
    public $title;
    public $city;
    public $region;
    public $district;
    public $address;
    public $price_amount;
    public $price_currency;
    public $area_total;
    public $land_area;
    public $rooms;
    public $floor;
    public $floors;
    public $built_year;
    public $has_3d_tour;
    public $media_links;
    public $description;
    public $features_text;
    public $owner_name;
    public $owner_phone;
    public $owner_email;
    public $preferred_contact;
    public $source_page;
    public $reviewed_at;
    public $review_note;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_property_submissions');
    }
}
