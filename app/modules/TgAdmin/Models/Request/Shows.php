<?php

namespace Modules\TgAdmin\Models\Request;

use Modules\TgAdmin\Models\Estate\Objects;
use Modules\TgAdmin\Models\Featuring\PresentationModel;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Modules\TgAdmin\Models\Person\AppUsers;
use Modules\TgAdmin\Featuring\Buttons;


abstract class Shows extends PresentationModel
{
    public $author_id;
    public $object_id;
    public $request_id;
    public $accountable_id;
    public $is_cooperation;
    public $client_type;
    public $status;
    public $result;
    public $description;
    public $showing_at;

    public function initialize()
    {
        $this->setSource('request_shows');
    }

    public function setDataFromConversation($notes){
        parent::setDataFromConversation($notes);
        $this->description .= PHP_EOL . PHP_EOL . '[' . date('d.m.Y H:i') . '] ('
            . $this->t($notes["status"] ). ')' . PHP_EOL . $notes["description"];
        $this->showing_at = $notes["showing_at"] . ' ' . $notes["showing_at_time"];
    }
    static function getDataArray($id)
    {
        $showin_data = self::findFirst("id='" . $id . "'");

        if (!$showin_data) {return false;}

        $result = (array)$showin_data;

        $data_time = explode(' ', $showin_data->showing_at);
        $result['description'] = null;
        $result['showing_at'] = $data_time[0] ?? null;
        $result['showing_at_time'] = $data_time[1] ?? null;

        return $result;
    }


    public function getThumbUrl()
    {
        return false;
    }

    public function getJSON()
    {
        // TODO: Implement getJSON() method.
    }
    public static function getWEB(array $data)
    {
        // TODO: Implement getWEB() method.
    }

    public static function getTittle(array $data)
    {
        // TODO: Implement getTittle() method.
    }

    public function getDescription()
    {
        // TODO: Implement getDescription() method.
    }

    public function getTGCard(int $user_id, array $params)
    {
        // TODO: Implement getTGCard() method.
    }
}