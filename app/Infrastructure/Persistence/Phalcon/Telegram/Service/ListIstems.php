<?php


namespace Infrastructure\Persistence\Phalcon\Telegram\Service;

use Infrastructure\Persistence\Phalcon\Telegram\Featuring\BaseModel;


class ListIstems extends BaseModel
{
    public $list_id;
    public $item_id;

    public function initialize()
    {
        $this->setSource('service_lists_items');
    }



}

