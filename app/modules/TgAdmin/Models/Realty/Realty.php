<?php
/**
 * Created by PhpStorm.
 * User: think
 * Date: 25.07.17
 * Time: 14:20
 */

namespace Modules\TgAdmin\Models\Realty;
use Modules\TgAdmin\Models\Featuring\ReObjects;


class Realty extends ReObjects
{

    public $message_id;
    public $tittle;
    public $category;

    public function save_arr($notes): void
    {
        $this->assign($notes);

        $kof = 41.3;  //changed 28.08.24; old - 37.6 12.07.23;  old - 27.2
        $this->price_UAH = $this->price_UAH ?? ($this->currency == 'UAH' ? $this["price"] : round($notes["price"] * $kof, 2));
        $this->price_USD = $notes["price_USD"] ?? ($notes["currency"] == 'USD' ? $notes["price"] : round($notes["price"] / $kof, 2));

        Parent::save();
    }
}