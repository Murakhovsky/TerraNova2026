<?php
declare(strict_types=1);

namespace Common\Models\RealEstate;

use Phalcon\Mvc\Model;

class PropertyType extends Model
{
    public $id;
    public $code;
    public $name_uk;
    public $sort_order;
    public $is_active;
    public $created_at;
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('tn_property_types');
    }
}
