<?php
/**
 * Created by PhpStorm.
 * User: think
 * Date: 25.07.17
 * Time: 14:20
 */

namespace Modules\TgAdmin\Models\Estate;

use Modules\TgAdmin\Models\Featuring\ReObjects;


class Objects extends ReObjects
{

    public $message_id;
    public $tittle;

    public $category;



    public function initialize()
    {
        $this->setSource('estate_objects');
    }

    public static function getObjectsByListId($list_id){
        $phql = "
        SELECT eo.*
        FROM Estate\Objects AS eo
        JOIN Service\ListsItems AS fi ON fi.item_id = eo.id
        WHERE fi.list_id = :list_id:
        ORDER BY eo.id DESC";

        return parent::executeQuery($phql, [
            'list_id' => $list_id
        ]);

    }




}