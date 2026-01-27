<?php


namespace Modules\TgAdmin\Models\Featuring;

use Longman\TelegramBot\Exception\TelegramException;
use Modules\TgAdmin\Featuring\Messages;

abstract class PresentationModel extends BaseModel
{
//

    abstract public static function getTittle(array $data);
    abstract public function getDescription();
    abstract public function getThumbUrl();
    abstract public function getTGCard(int $user_id, array $params);


    public static function getTittleByID($id){
        $data = self::findFirstById($id);
        return static::getTittle((array)$data);
    }

    public function getInlineData(int $user_id, array $params = array())
    {

        $result = array();
//        Messages::sendMeMessage("test1");
        $result["title"] = $this::getTittle((array)$this);
//        Messages::sendMeMessage("test2");
        $result["description"] = $this->getDescription();

//        Messages::sendMeMessage("test3");
        if($thumbURL = $this->getThumbUrl()) {
            $result["thumb_url"] = str_contains($thumbURL, "https:") ? $thumbURL : DOMAIN_NAME . $thumbURL;
        }
//        Messages::sendMeMessage("test4");
        $TGCard = $this->getTGCard($user_id, $params);
//        Messages::sendMeMessage("test5");exit;
        return $result + $TGCard;
    }

}