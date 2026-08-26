<?php
declare(strict_types=1);

namespace Common\Models\RealEstate;

use Phalcon\Mvc\Model;

class PropertyGroup extends Model
{
    public $id;
    public $title;
    public $slug;
    public $group_type;
    public $location_id;
    public $address;
    public $description;
    public $image_url;
    public $status;
    public $sort_order;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_property_groups');
    }
}
