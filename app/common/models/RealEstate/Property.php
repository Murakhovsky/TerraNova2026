<?php
declare(strict_types=1);

namespace Common\Models\RealEstate;

use Phalcon\Mvc\Model;

class Property extends Model
{
    public $id;
    public $public_id;
    public $slug;
    public $title;
    public $deal_type;
    public $type_id;
    public $status;
    public $source_type;
    public $location_id;
    public $agent_id;
    public $price_amount;
    public $price_currency;
    public $price_period;
    public $area_total;
    public $area_living;
    public $land_area;
    public $rooms;
    public $bedrooms;
    public $bathrooms;
    public $floor;
    public $floors;
    public $built_year;
    public $address;
    public $latitude;
    public $longitude;
    public $short_description;
    public $description;
    public $features_json;
    public $is_featured;
    public $has_3d_tour;
    public $tour_url;
    public $video_url;
    public $meta_title;
    public $meta_description;
    public $published_at;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_properties');
    }
}
