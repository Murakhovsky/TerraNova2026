<?php


namespace Modules\TgAdmin\Models\Featuring;

use Longman\TelegramBot\Exception\TelegramException;
use Modules\TgAdmin\Models\Estate\Objects;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Query;
use Phalcon\Di\Di;

abstract class BaseModel extends Model
{
    public ?int $id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
//

    public abstract function new();
    public abstract function edit();
    public function setDataFromConversation($notes){// deprecated
        foreach($notes as $key => $value) {
            if(property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }


    public function showData(){
        $text = "start" . PHP_EOL;
        foreach ($this->toArray() as $key => $item){
            $text .= $key . " - " . $item . PHP_EOL;
        }
        $this->replyToChat($text);
    }

    public static function executeQuery($phql, $params = []){
        $query = new Query(
            $phql,
            Di::getDefault()
        );
        return $query->execute($params);
    }


    public static function getThisByTypeID(string $type, int $id, string $param = null) // deprecated
    {
        switch ($type){
            case 'reamak':{
                $model =  '\EB_DB\RealtyReamak';
                break;
            }
            case 'object':{
                $model =  'Modules\TgAdmin\Models\Estate\Objects';
                break;
            }
            case 'AdvertTG':
            case 'advertTG':{
                $model =  'Modules\TgAdmin\Models\Estate\Adverts';
                break;
            }
            case 'advert':{
                $model = '\Parser\Advert\Realty' . $param;
                break;
            }
            default :
            {
                $model = '\TelegramModels\TelegramEb' . ucfirst($type);
            }
        }


//        if(!($estate_object_model instanceof TelegramBaseModel)){
        if(!class_exists($model)) {
            throw new TelegramException('Can\'t get Model class in ' . $type );
        }
        return $model::findFirst($id);
    }

    public function saveModel()
    {
        if ($this->getDirtyState() === Model::DIRTY_STATE_TRANSIENT) {
            // Новий об’єкт
            $this->new();
        } else {
            // Існуючий, буде оновлений
            $this->edit();
        }
        $object = parent::save();
        return $object;
    }
}