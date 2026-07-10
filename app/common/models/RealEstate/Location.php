<?php
declare(strict_types=1);

namespace Common\Models\RealEstate;

use Phalcon\Mvc\Model;

class Location extends Model
{
    public $id;
    public $country_code;
    public $region;
    public $city;
    public $district;
    public $slug;
    public $latitude;
    public $longitude;
    public $sort_order;
    public $is_active;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_locations');
    }
}
