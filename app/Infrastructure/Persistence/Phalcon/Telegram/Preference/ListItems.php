<?php


namespace Infrastructure\Persistence\Phalcon\Telegram\Preference;

use Infrastructure\Persistence\Phalcon\Telegram\Featuring\BaseModel;


class ListItems extends BaseModel
{
    public $list_id;
    public $item_id;

    public function initialize()
    {
        $this->setSource('service_lists_items');
    }



}

