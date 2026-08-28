<?php


namespace Infrastructure\Persistence\Phalcon\Telegram\Featuring;

use Longman\TelegramBot\Exception\TelegramException;
use Infrastructure\Persistence\Phalcon\Telegram\Estate\Objects;
use Infrastructure\Persistence\Phalcon\Telegram\Estate\Adverts;
use Infrastructure\Persistence\Phalcon\Telegram\Realty\RealtyReamak;
use Infrastructure\Persistence\Phalcon\Telegram\Request\Requests;
use Infrastructure\Persistence\Phalcon\Telegram\Request\Shows;
use Infrastructure\Persistence\Phalcon\Telegram\Service\Lists;
use Infrastructure\Persistence\Phalcon\Identity\Telegram\Company\Companies;
use Infrastructure\Persistence\Phalcon\Identity\Telegram\Person\AppUsers;
use Phalcon\Mvc\Model;
use Phalcon\Mvc\Model\Query;
use Phalcon\Di\Di;

abstract class BaseModel extends Model
{
    public ?int $id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
//

    public function new()
    {
    }

    public function edit()
    {
    }
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
                $model = RealtyReamak::class;
                break;
            }
            case 'object':{
                $model = Objects::class;
                break;
            }
            case 'AdvertTG':
            case 'advertTG':{
                $model = Adverts::class;
                break;
            }
            case 'advert':{
                throw new TelegramException('External advert models are no longer supported.');
            }
            case 'user':
                $model = AppUsers::class;
                break;
            case 'showing':
                $model = Shows::class;
                break;
            case 'request':
                $model = Requests::class;
                break;
            case 'favourite':
                $model = Lists::class;
                break;
            case 'company':
                $model = Companies::class;
                break;
            default:
                throw new TelegramException('Unsupported model type: ' . $type);
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

