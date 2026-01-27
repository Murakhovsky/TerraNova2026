<?php
/**
 * Created by PhpStorm.
 * User: think
 * Date: 25.07.17
 * Time: 14:20
 */

namespace Modules\TgAdmin\Models\Featuring;

use Modules\TgAdmin\Models\Estate\Objects;
use Modules\TgAdmin\Models\Estate\ObjectsArchive;
use Modules\TgAdmin\Models\Estate\ObjectsDisabled;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Modules\TgAdmin\Models\Person\AppUsers;

use Modules\TgAdmin\Models\Service\Lists;
use Modules\TgAdmin\Featuring\Buttons;
use Modules\TgAdmin\Featuring\Messages;

class ReObjects extends BaseModel
{
    public $user_id;


    public $type;
    public $is_new;
    public $object_type;
    public $rooms;
    public $floor;
    public $floors;
    public $square_full;
    public $square_dwelling;
    public $square_kitchen;
    public $square_ground;

    public $condition;
    public $wall_material;
    public $description;
    public $add_description;

    public $location_code;
    public $latitude;
    public $longitude;
    public $city;
    public $region;
    public $street;

    public $photos_id;
    public $photos_url;
    public $media_group_id;

    public $price_UAH;
    public $price_USD;
    public $currency;

    public $contact_phone;
    public $contact_name;

    public $status;

    public $is_private;


    public function beforeSave(){
// in childs class
//        $kof = 41.3;  //changed 28.08.24; old - 37.6 12.07.23;  old - 27.2
//        $this->price_UAH = $this->price_UAH ?? ($this->currency == 'UAH' ? $this["price"] : round($notes["price"] * $kof, 2));
//        $this->price_USD = $notes["price_USD"] ?? ($notes["currency"] == 'USD' ? $notes["price"] : round($notes["price"] / $kof, 2));


        $this->rooms = str_replace(['r','+'], '', $this->rooms);
        $this->photos_id = json_encode($this->photos_id);
        $this->photos_url = json_encode($this->photos_url);
        $this->is_private = $this->is_private === 'yes' ? 1 : 0;
        $this->status = $this->status ?? 'unknown';
    }
    public function afterFetch()
    {
        $this->photos_id = json_decode($this->photos_id, true);
        $this->photos_url = json_decode($this->photos_url, true);
        $this->is_private = $this->is_private ? 'yes' : "no";
    }



    public function getArray(){

        $notes = (array) $this;

        $notes["price"] = $notes["currency"] == 'UAH' ? $this->price_UAH : $this->price_USD;
        $notes["photos_id"] = json_decode($this->photos_id);
        Messages::sendMeMessage(json_encode($this->photos_url));
        $notes["photos_url"] = json_decode($this->photos_url);
        $notes["photos"] = count(json_decode($this->photos_url));
        $notes["is_private"] = $this->is_private ? 'yes' : 'no';

        return $notes;
    }

    static public function searchRealty($filters, $page = 1, $count_rows = 5){
        $first_row = ($page-1) * $count_rows;

        $conditions = '';
        $bind = array();
        $price = 'price_';
        if(isset($filters['currency']) ){$price .= $filters['currency'];}
        else { $price .= $filters['category'] == 'rent'? 'UAH' : 'USD';}
        $regions = array(
            'zaliznychny' => 'Залізничний',
            'frankivsky' => 'Франківський',
            'lychakivsky' => 'Личаківський',
            'galician' => 'Галицький',
            'shevchenkivsky' => 'Шевченківський',
            'sykhivsky' => 'Сихівський',
        );

        if((get_called_class() == Objects::class || get_called_class() == ObjectsArchive::class) && isset($filters['user_id'])){
            $conditions .= 'user_id = :user_id: AND ';
            $bind['user_id'] = $filters['user_id'];
        };
//        case 'advert':
//            {
////                $model = $filters['category'] . $filters['type'];
//                $filters['category'] = null;
//                $filters['type'] = null;
//                $filters['is_private'] = null;
//                $filters['is_archive'] = null;
//                break;
//            }


        if(isset($filters['rooms'])){
            $rooms = explode('-', $filters['rooms']);
            if(isset($rooms[0])){
                $conditions .= 'rooms >= :rooms_from: AND ';
                $bind['rooms_from'] = (int)$rooms[0];
            }
            if(isset($rooms[1])){
                $conditions .= 'rooms <= :rooms_to: AND ';
                $bind['rooms_to'] = (int)$rooms[1];
            }
        }
        if(isset($filters['rooms_from'])){
            $conditions .= 'rooms >= :rooms_from: AND ';
            $bind['rooms_from'] = (int)$filters['rooms_from'];
        }
        if(isset($filters['rooms_to'])){
            $conditions .= 'rooms <= :rooms_to: AND ';
            $bind['rooms_to'] = (int)$filters['rooms_to'];
        }
        if(isset($filters['beds'])){
            $beds = explode('-', $filters['beds']);
            if(isset($beds[0])){
                $conditions .= 'beds >= :beds_from: AND ';
                $bind['beds_from'] = (int)$beds[0];
            }
            if(isset($beds[1])){
                $conditions .= 'beds <= :beds_to: AND ';
                $bind['beds_to'] = (int)$beds[1];
            }
        }
        if(isset($filters['beds_from'])){
            $conditions .= 'beds >= :beds_from: AND ';
            $bind['beds_from'] = (int)$filters['beds_from'];
        }
        if(isset($filters['beds_to'])){
            $conditions .= 'beds <= :beds_to: AND ';
            $bind['beds_to'] = (int)$filters['beds_to'];
        }
        if(isset($filters['floor'])){
            //можна ще додати можливість floor >= :floor_from: AND floor <> floors
            $floor = explode('-', $filters['floor']);
            if (isset($floor[0]) && $floor[0] == '41') {
                $conditions .= 'floor = floors AND ';
            }elseif (isset($floor[1]) && $floor[1] == '41') {
                $conditions .= 'floor <> floors AND ';
            }else{
                if (isset($floor[1])) {
                    $conditions .= 'floor <= :floor_to: AND ';
                    $bind['floor_to'] = (int)$floor[1];
                }
                if (isset($floor[0])) {
                    $conditions .= 'floor >= :floor_from: AND ';
                    $bind['floor_from'] = (int)$floor[0];
                }
            }
        }
        if(isset($filters['floor_from'])){
            if($filters['floor_from'] == '41'){
                $conditions .= 'floor = floors AND ';
            }else {
                $conditions .= 'floor >= :floor_from: AND ';
                $bind['floor_from'] = (int)$filters['floor_from'];
            }
        }
        if(isset($filters['floor_to'])){
            if($filters['floor_to'] == '41'){
                $conditions .= 'floor <> floors AND ';
            }else {
                $conditions .= 'floor <= :floor_to: AND ';
                $bind['floor_to'] = (int)$filters['floor_to'];
            }
        }
        if(isset($filters['floors'])){
            $floors = explode('-', str_replace('м2', '', $filters['floors']));

            if(isset($floors[0])){
                if($floors[0] == '0'){
                    $conditions .= 'floor <> floors AND ';
                }else {
                    $conditions .= 'floor >= :floor_from: AND ';
                    $bind['floor_from'] = (int)$floors[0];
                }
            }
            if(isset($floors[1])){
                $conditions .= 'floor <= :floor_to: AND ';
                $bind['floor_to'] = (int)$floors[1];
            }
        }
        if(isset($filters['square'])){
            $square = explode('-', str_replace('м2', '', $filters['square']));
            if(isset($square[0])){
                $conditions .= 'square_full >= :square_from: AND ';
                $bind['square_from'] = (int)$square[0];
            }
            if(isset($square[1])){
                $conditions .= 'square_full <= :square_to: AND ';
                $bind['square_to'] = (int)$square[1];
            }
        }
        if(isset($filters['square_from'])){
            $conditions .= 'square_full >= :square_from: AND ';
            $bind['square_from'] = (float)$filters['square_from'];
        }
        if(isset($filters['square_to'])) {
            $conditions .= 'square_full <= :square_to: AND ';
            $bind['square_to'] = (float)$filters['square_to'];
        }
        if(isset($filters['square_ground_from'])){
            $conditions .= 'square_ground >= :square_ground_from: AND ';
            $bind['square_ground_from'] = (float)$filters['square_ground_from'];
        }
        if(isset($filters['square_ground_to'])) {
            $conditions .= 'square_ground <= :square_ground_to: AND ';
            $bind['square_ground_to'] = (float)$filters['square_ground_to'];
        }
        if(isset($filters['price'])){
            $prices = explode('-', str_replace('м2', '', $filters['price']));
            if(isset($prices[0])){
                $conditions .= $price . ' >= :price_from: AND ';
                $bind['price_from'] = (int)$prices[0];
            }
            if(isset($prices[1])){
                $conditions .= $price . ' <= :price_to: AND ';
                $bind['price_to'] = (int)$prices[1];
            }
        }
        if(isset($filters['price_from'])) {
            $conditions .= $price . ' >= :price_from: AND ';
            $bind['price_from'] = (float)$filters['price_from'];
        }
        if(isset($filters['price_to'])) {
            $conditions .= $price . ' <= :price_to: AND ';
            $bind['price_to'] = (float)$filters['price_to'];
        }
//        if(isset($filters['city'])){
//            $conditions .= 'city = :city: AND ';
//            $bind['city'] = $filters['city'];
//        }
        if(isset($filters['region'])){
            $region = $filters['source'] == 'advert' ? $regions[$filters['region']] : $filters['region'];
            $conditions .= 'region = :region: AND ';
            $bind['region'] = $region;
        }
        if(isset($filters['term'])){
            $conditions .= 'term = :term: AND ';
            $bind['term'] = $filters['term'];
        }
        if(isset($filters['type'])){
            $conditions .= 'type = :type: AND ';
            $bind['type'] = $filters['type'];
        }
        if(isset($filters['category'])){
            $conditions .= 'category = :category: AND ';
            $bind['category'] = $filters['category'];
        }
        if(isset($filters['advert_code'])){
            $conditions .= 'advert_code = :advert_code: AND ';
            $bind['advert_code'] = $filters['advert_code'];
        }
        if(isset($filters['status'])){
            $conditions .= 'status = :status: AND ';
            $bind['status'] = $filters['status'];
        }
        // #todo fix problem with is_private and is_archive and region
//        if(isset($filters['is_private'])){
//            $conditions .= 'is_private = :is_private: AND ';
//            $bind['is_private'] = $filters['is_private'];
//        }
//        if(isset($filters['is_archive'])){
//            $conditions .= 'is_archive = :is_archive: AND ';
//            $bind['is_archive'] = $filters['is_archive'];
//        }

        $data = array(
            'order' => 'updated_at DESC',
            'limit' => $count_rows,
            'offset' => $first_row,
        );
        if($conditions){
            $data['conditions'] = substr($conditions, 0, -4);
            $data['bind'] = $bind;


        }

//        Request::sendMessage(
//            [
//                'chat_id' => 522625209,
//                'text' => $data['conditions'] . PHP_EOL . serialize( $data['bind'] )
//            ]
//        );

        return self::find($data);
    }

    static public function getRealtyByUser($type, $user_id){
        $data = array(
            'conditions' => 'user_id = :user_id:',
            'bind' => array('user_id' => $user_id)
        );
        return self::getFromDB($type, $data);
    }

    static private function getFromDB($class, $data): array|null{
        return $class::find($data);
    }

    public function setDataFromConversation($notes){
        parent::setDataFromConversation($notes);

        $kof = 38.6;  //changed 12.03 old - 35,5  older - 27.2
        $this->is_new = isset($notes["is_new"]) ? ($notes["is_new"] ? true : false) : null;
        $this->rooms = str_replace(['r','+'], '', $notes["rooms"]);
        if(isset($notes["price"]) && $notes["price"]){
            $p_uah = $notes["currency"] == 'UAH' ? $notes["price"] : round($notes["price"] * $kof, 2);
            $p_usd = $notes["currency"] == 'USD' ? $notes["price"] : round($notes["price"] / $kof, 2);
        }else{
            $p_uah = $notes["price_UAH"] ?? 1;
            $p_usd = $notes["price_USD"] ?? 1;
        }

        $this->price_UAH = $p_uah;
        $this->price_USD = $p_usd;
        $this->photos_id = $notes["photos_id"] ?? '';
        $this->photos_url = $notes["photos_url"] ?? '';
        $this->is_private = isset($notes["is_private"]) ? ($notes["is_private"] ? 1 : 0) : null;
        $this->status = isset($notes["status"]) && $notes["status"] ? $notes["status"] : 'active';
        $this->updated_at = date('Y-m-d H:i:s');
    }

    static function getDataArray($id)
    {
        $object_data = self::findFirst("id='" . $id . "'");

        if (!$object_data) {
            return false;
        }
        $notes = (array) $object_data;
        $notes["is_new"] = $object_data->is_new ? true : false;
        $notes["price"] = $notes["currency"] == 'UAH' ? $object_data->price_UAH : $object_data->price_USD;
        $notes["photos_id"] = json_decode($object_data->photos_id);
        $notes["photos_url"] = json_decode($object_data->photos_url);
        $notes["photos"] = count($notes["photos_url"]);
        $notes["is_private"] = $object_data->is_private ? true : false;

        return $notes;
    }


    public function getPost(array $params = array()){
        $chat_username = $this->category == 'sale' ? '@salelvivadverts' : '@rentlvivadverts';
// TODO закріпити username в повідомленні
        $params["model"] = "AdvertTG";

//        $photo = json_decode($this->photos_id ? $this->photos_id : $this->photos_url)[0];

        $command = 'AdvertTG,' . $this->id . ',AdvertTG,4,as_advert';
        $inline_keyboard[] = [
//            Buttons::getButton('Зберегти', 'inlinekeyboard;show_card;'  . $params),
            Buttons::getButton('В обрані', 'inlinekeyboard;setToList;' . $command),
            Buttons::getButton('Не актуально', 'inlinekeyboard;not_actual;' . $command ),
        ];

        return array(
            'text' => self::getPostMessage((array)$this, $params),
//            'photo' => $photo,
            'chat_id' => $chat_username,
            'parse_mode' => 'HTML',
            'reply_markup' => new InlineKeyboard(...$inline_keyboard),
        );
    }

    public static function getPostMessage(array $data, array $params){
        $currency = $data['currency'] ?? 'USD';
        $price = $data['price_' . $currency] ?? $data['price'];

        $text = '<b>' . self::getTittle((array)$data) . '</b>' . PHP_EOL ;

        $text .= $data['floor'] || $data['floors'] ? PHP_EOL . '<b>Поверх:</b> ' . ($data['floor'] ?? '-') . '/' . ($data['floors'] ?? '-') : '';
        $square_dwelling = $data['square_dwelling'] ? $data['square_dwelling'] : '-';
        $square_kitchen = $data['square_kitchen'] ? $data['square_kitchen'] : '-';
        $text .= $data['square_full'] ? PHP_EOL . '<b>Площа:</b>  ' . $data['square_full'] . '/' . $square_dwelling . '/' . $square_kitchen . ' м2' : '';
        $text .= $data['square_ground'] ? PHP_EOL . '<b>Площа ділянки:</b>  ' . $data['square_ground'] . 'сот.' : ' ';

        $text .= $price ? PHP_EOL . '<b>Ціна:</b> ' . $price . $currency : '';
        if(isset($params["is_presentation"]) && $params["is_presentation"] === false) {

            $text .= $data['contact_name'] ? PHP_EOL . '<b>Ім\'я:</b> ' . $data['contact_name'] . '' : ' ';
            $text .= $data['contact_phone'] ? PHP_EOL . '<b>Телефон:</b> <a href="tel:' . $data['contact_phone'] . '">' . $data['contact_phone'] . '</a>' : ' ';
//            $text .= $data['user_name'] ? '<br><b>Username:</b> @' . $data['user_name'] : ' ';

            if ($data['id']) {
                $not_actual = ObjectsDisabled::find("object_id=" . $data["id"] . " AND type='" . $params["model"] . "'");
                $text .= PHP_EOL . '<b>Актуально:</b>  ' . (count($not_actual) ? '⚠ ' . count($not_actual) : '✅ актуально');
            }

            /** Adding hash tags **/
            $_types = array('flat' => 'кв', 'house' => 'буд', 'ground' => 'діл', 'commerce' => 'ком', 'else' => 'ін');
            $type = $_types[$data['type']] ?? 'інше';
            $rooms = isset($data['rooms']) ? ((int)$data['rooms'] < 4 ? $data['rooms'] : '4') : '';
            $city = isset($data['city']) ? $data['city'] : '';
            $regionF = defined('TG_' . strtoupper($data['region'])) ? self::const(strtoupper($data['region'])) : $data['region'];
            $region = $regionF ? trim(mb_substr($regionF, 0, 5)) : '';

            $is_new = $data['is_new'] == 'yes' ? 'нов' : '';
            $flore = '';
            if ($data['floor'] && $data['floors']) {
                if ($data['floor'] != '1' && $data['floor'] != $data['floors']) {
                    $flore = 'нпо';
                }
            }
            $object_type = $data['object_type'] ? $data['object_type'] : '';
            if ($data['category'] == 'rent') {
                $_price = $data['price_UAH'];
                $_prices = array(0, 6, 10, 15, 20, 25, 32, 42);
            } else {
                $_price = $data['price_USD'];
                $_prices = array(0, 20, 35, 50, 70, 90, 120, 180, 300, 500);
            }
            $_price = (int)($_price / 1000);

            $price = end($_prices) . '_999К';
            foreach ($_prices as $key => $value) {
                if ($_price <= $value) {
                    $price = $_prices[$key - 1] . '_' . $_prices[$key] . 'К';
                    break;
                }
            }


            $hashtags = ($city ? ' #' . (defined('TG_' . $data['city']) ? self::const($data['city']) : $data['city']) : '')
                . ($data['type'] ? ' #' . self::const($data['type']) : '')
                . ($regionF ? ' #' . $regionF : '') . PHP_EOL
                . ($rooms ? ' #' . $type . '_' . $rooms . 'к' : '')
                . ($price ? ' #' . $type . '_' . $price : '')
                . ($rooms && $price ? ' #' . $type . '_' . $rooms . 'к_' . $price : '')
                . ($region ? ' #' . $region . '_' . $type : '') . PHP_EOL
                . ($rooms && $region ? ' #' . $region . '_' . $type . '_' . $rooms . 'к' : '')
                . ($price && $region ? ' #' . $region . '_' . $type . '_' . $price : '') . PHP_EOL
                . ($rooms && $price && $region ? ' #' . $region . '_' . $type . '_' . $rooms . 'к_' . $price : '')
                . ($object_type ? ' #' . str_replace(' ', '_', trim($object_type)) : '');


//
//            . ($is_new ? ' #' . $type . '_' . $is_new : '')
//            . ($flore ? ' #' . $type . '_' . $flore : '')
//
//            . ($rooms ? ' #' . $type . '_' . $rooms . 'к' : '')
////            . ($beds ? ' #' . $beds . 'місць' : '')
//            . ($rooms && $is_new ? ' #' . $type . '_' . $rooms . 'к_' . $is_new : '')
//            . ($rooms && $flore ? ' #' . $type . '_' . $rooms . 'к_' . $flore : '')
//            . ($rooms ? ' #' . $type . '_' . $rooms . 'к_' . $price : '')
//            . ($rooms && $is_new && $flore ? ' #' . $type . '_' . $rooms . 'к_' . $is_new . '_' . $flore : '')
//            . ($rooms && $is_new ? ' #' . $type . '_' . $rooms . 'к_' . $is_new . '_' . $price : '')
//            . ($rooms && $is_new && $flore ? ' #' . $type . '_' . $rooms . 'к_' . $is_new . '_' . $flore . '_' . $price : '')
//            . ($object_type ? ' #' . str_replace(' ', '_', $object_type) : '');

            $text .= PHP_EOL . PHP_EOL;
            $text .= $hashtags;
            /**
             *   #Львів #квартира #личаківський
             *   #кв_1к #кв_40-60 #кв_1к_40-60
             *   #личак_кв #личак_кв_1к #личак_кв_40-60
             *   #object_type
             *
             *   #кв_1к_нпо #кв_40-60_нпо #кв_личак_нпо
             *   #кв_1к_40-60_нпо #кв_1к_личак_нпо #кв_40-60_личак_нпо
             *   #кв_1к_нов #кв_40-60_нов #кв_личак_нов
             *   #кв_1к_40-60_нов #кв_1к_личак_нов #кв_40-60_личак_нов
             *   #кв_1к_нов_нпо #кв1к_нов_40-60
             *   #кв_1к_нов_нпо_40-60 #object_type
             *   #кв_нов
             */
            $is_p='';
        }else{
            $is_p='%2Fp';
        }
        $url = 'https://t.me/iv?url=https%3A%2F%2Festatebook.space%2FInstantView%2Fpage%2F' . $params["model"] . '%2F' . $data["id"] . $is_p . '&rhash=391d4fee21073d';
//        $url = 'https://t.me/iv?url=https%3A%2F%2Festatebook.space%2FInstantView%2Fpage%2FRentFlat%2F58274&rhash=391d4fee21073d';
        $text .= '       <a href="' . $url . '">  &#160;  </a>';

        return mb_convert_encoding($text, "UTF-8");
    }


}