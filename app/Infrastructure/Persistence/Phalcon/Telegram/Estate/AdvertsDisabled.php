<?php


namespace Infrastructure\Persistence\Phalcon\Telegram\Estate;
use Infrastructure\Persistence\Phalcon\Telegram\Featuring\BaseModel;

class AdvertsDisabled extends BaseModel
{
    public $advert_id;
    public $employee_id;
    public $rating;

    public function initialize()
    {
        $this->setSource('estate_objects_disabled');
    }



}

