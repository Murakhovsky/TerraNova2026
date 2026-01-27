<?php


namespace Modules\TgAdmin\Models\Request;

use Modules\TgAdmin\Models\Featuring\BaseModel;

class RequestJoinRealty extends BaseModel
{
    public $object_id;
    public $request_id;
    public $status;
    public $date;

    public function initialize()
    {
        $this->setSource('request_join_realty');
    }
    public function setDataFromConversation($notes)
    {
//        $this->id = $notes["id"];
        $this->object_id = $notes["object_id"];
        $this->request_id = $notes["request_id"];
        $this->status = $notes["status"];
        $this->date = isset($notes["date"]) ? $notes["date"] : date('Y-m-d H:i:s');
    }

    public static function getDataArray($id){
        $request_data = self::findFirst("id='".$id."'");
        if (!$request_data) {
            return array();
        }
        return (array) $request_data;
    }
    public static function isInDB($object_id, $request_id){
        return self::count([
            "object_id = :o_id: AND request_id = :r_id:",
            "bind" => [
                "o_id" => $object_id,
                "r_id" => $request_id
            ]
        ]);
    }

    public static function getByPear($object_id, $request_id){
        return self::findFirst([
            "object_id = :o_id: AND request_id = :r_id:",
            "bind" => [
                "o_id" => $object_id,
                "r_id" => $request_id
            ]
        ]);
    }
}