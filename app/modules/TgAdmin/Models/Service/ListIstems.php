<?php


namespace Modules\TgAdmin\Models\Service;

use Modules\TgAdmin\Models\Featuring\BaseModel;


class ListIstems extends BaseModel
{
    public $list_id;
    public $item_id;

    public function initialize()
    {
        $this->setSource('service_lists_items');
    }



}