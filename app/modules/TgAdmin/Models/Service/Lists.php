<?php


namespace Modules\TgAdmin\Models\Service;

use Modules\TgAdmin\Models\Featuring\PresentationModel;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Modules\TgAdmin\Featuring\Buttons;


class Lists extends PresentationModel
{
    public $user_id; // employee_id
    public $list_name;
    public $is_private;
    public $source;
    public $items_type;
    public $status;

    public function initialize()
    {
        $this->setSource('service_lists');
    }
    public function beforeSave(){
        $this->is_private = $this->is_private == "yes" ? 1 : 0;
    }
    public function afterFetch(){
        $this->is_private = $this->is_private == 1 ? 'yes' : 'no';
    }


    public static function getListByObjectId($object_id, $my_id){
        $phql =
        "SELECT fl.list_name
        FROM " . Lists::class . " AS fl
        JOIN ". ListIstems::class . " AS fi ON fi.list_id = fl.id
        WHERE fl.user_id = :user_id: AND fi.item_id = :item_id:
        ORDER BY fl.id DESC";

        return parent::executeQuery($phql, [
            'user_id' => $my_id,
            'item_id' => $object_id
        ]);
//        $favouriteModel = 'TelegramModels\TelegramEbFavourite';
//        $favouriteItemsModel = 'TelegramModels\Featuring\TelegramEbFavouriteItems';
//        $realtyModel = 'TelegramModels\Adverts\RealtyTg';
//
//        $queryBuilder = new Builder();
//
//        $queryBuilder->columns($favouriteModel . '.list_name');
//        $queryBuilder->from([$favouriteItemsModel, $favouriteModel]);
//        $queryBuilder->where($favouriteItemsModel.'.item_i d=?0', [$object_id]);
//        $queryBuilder->andWhere($favouriteModel.'.user_id = '. $my_id);
//        $queryBuilder->andWhere($favouriteModel.'.id = '. $favouriteItemsModel . '.list_id');
////            $queryBuilder->join($favouriteItemsModel, $favouriteItemsModel . '.list_id = ' . $favouriteModel . '.id');
//        $queryBuilder->orderBy($favouriteModel . '.id DESC');
//
//        return $queryBuilder->getQuery()->execute();
    }

    static function getDataArray($id){

        $notes = parent::getDataArray($id);

        #todo звести is_private до bool і видалити наступний кусок
        $favouriteData = self::findFirst("id='".$id."'");
        if(!$favouriteData){return false;}
        $notes["is_private"] = $favouriteData->is_private ? 'yes' : 'no';

        return $notes;
    }

    public function getTGCard(int $user_id, array $params)
    {
        $inline_keyboard = array(
            array(
                Buttons::getButton(TG_OBJECTS, 'fid_' . $this->id, 'switch_inline_query_current_chat'),
                Buttons::getButton(TG_OBJECTS . ' ⤴', 'fid_' . $this->id, 'switch_inline_query')
            )
        );

        if($user_id == $this->user_id) {
            $inline_keyboard[] = Buttons::getAdminLine('favourite', $this->id);
        }
        $inline_keyboard[] = Buttons::getControlLine('favourite,' . $this->id);

        $text = $this::getTGText((array)$this, $params);
        return array(
            'parse_mode' => 'HTML',
            'text' => mb_convert_encoding($text, "UTF-8"),
            'message_text' => mb_convert_encoding($text, "UTF-8"),
            'reply_markup' => new InlineKeyboard(...$inline_keyboard)
        );

    }

    public static function getTGText(array $data, array $params = array())
    {
        $text = '<b>Списки об\'єктів: </b> ' .  PHP_EOL;
        $text .= $data['list_name'] ? '<b>Назва:</b> ' . $data['list_name'] . PHP_EOL : '';
//        $text .= '<b>Об\'єкти:</b> (в розробці)' . PHP_EOL;// $this->notes['list'] . PHP_EOL;
        $text .= $data['is_private'] ? '<b>Список приватний:</b> ' . self::const($data['is_private']) . PHP_EOL : '';
        $text .= $data['published_at'] ? '<b>Опублікований:</b> ' . $data['published_at'] . PHP_EOL : '';

//        $text .= '<b>Джерело:</b> (в розробці) ' . PHP_EOL; // $this->notes['source'] . PHP_EOL;
//        if($data['list']) {
//            $text .= '<b>Списки: </b>';
//            foreach (json_decode($data['list']) as $list){
//                $text .= $list . '; ';
//            }
//        };
        $text .= $data['source'] ? '<b>Джерело: </b>' . $data["source"] : '';

        return mb_convert_encoding($text, "UTF-8");
    }

    public static function getTittle(array $data)
    {
        return $data["list_name"];
    }
    public function getDescription()
    {
        return $this->is_private ? 'Приватний ' : 'Публічний';
    }
    public function getThumbUrl()
    {
        return false;
    }

    public static function getWEB(array $data)
    {
        // TODO: Implement getWEB() method.
    }
    public function getJSON()
    {
        // TODO: Implement getJSON() method.
    }

}