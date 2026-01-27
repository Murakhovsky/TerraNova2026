<?php

namespace Modules\TgAdmin\Models\Estate;

use Modules\TgAdmin\Models\Featuring\BaseModel;

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