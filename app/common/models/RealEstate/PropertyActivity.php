<?php
declare(strict_types=1);

namespace Common\Models\RealEstate;

use Phalcon\Mvc\Model;

class PropertyActivity extends Model
{
    public $id;
    public $property_id;
    public $user_id;
    public $activity_type;
    public $title;
    public $body;
    public $old_value;
    public $new_value;
    public $created_at;

    public function initialize(): void
    {
        $this->setSource('tn_property_activities');
    }
}
