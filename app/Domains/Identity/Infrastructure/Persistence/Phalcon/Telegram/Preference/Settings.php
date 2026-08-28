<?php

namespace Domains\Identity\Infrastructure\Persistence\Phalcon\Telegram\Preference;
use Phalcon\Mvc\Model;

class Settings extends Model
{
    public $id;
    public $employee_id;
    public $type;
    public $item_option;
    public $item_value;

    public function initialize()
    {
        $this->setSource('service_settings');
    }

}
