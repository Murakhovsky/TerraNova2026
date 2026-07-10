<?php
declare(strict_types=1);

namespace Common\Models\RealEstate;

use Phalcon\Mvc\Model;

class PropertyImage extends Model
{
    public $id;
    public $property_id;
    public $image_url;
    public $alt_text;
    public $sort_order;
    public $is_cover;
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('tn_property_images');
    }
}
