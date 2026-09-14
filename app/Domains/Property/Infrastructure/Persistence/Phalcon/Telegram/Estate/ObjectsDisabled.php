<?php


namespace Domains\Property\Infrastructure\Persistence\Phalcon\Telegram\Estate;
use Infrastructure\Integration\Telegram\ActiveRecord\BaseModel;


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

