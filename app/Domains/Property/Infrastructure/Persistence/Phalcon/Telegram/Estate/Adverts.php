<?php

namespace Domains\Property\Infrastructure\Persistence\Phalcon\Telegram\Estate;

use Infrastructure\Integration\Telegram\ActiveRecord\BaseModel;

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

