<?php

namespace Infrastructure\Persistence\Phalcon\Telegram\Estate;

use Infrastructure\Persistence\Phalcon\Telegram\Featuring\BaseModel;

class Adverts extends BaseModel
{
    public $object_id;
    public $employee_id;
    public $market;
    public $status;
    public $rating;

    public function initialize()
    {
        $this->setSource('estate_adverts');
    }
}

