<?php
declare(strict_types=1);

namespace Common\Models\RealEstate;

use Phalcon\Mvc\Model;

class PropertyFeature extends Model
{
    public $id;
    public $property_id;
    public $feature_key;
    public $feature_value;
    public $sort_order;
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('tn_property_features');
    }
}
