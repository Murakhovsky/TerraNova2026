<?php


namespace Modules\TgAdmin\Models\Estate;
use Modules\TgAdmin\Models\Featuring\BaseModel;


class ObjectsDisabled extends BaseModel
{
    public $object_id;
    public $employee_id;
    public $rating;

    public function initialize()
    {
        $this->setSource('estate_objects_disabled');
    }


}