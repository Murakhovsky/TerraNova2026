<?php

namespace Modules\TgAdmin\Featuring;

use http\Params;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use TelegramModels\Advert;
use TelegramModels\Adverts\RealtyTg;
use TelegramModels\TelegramBaseModel;
use TelegramModels\TelegramEbCompany;
use TelegramModels\TelegramEbEmployees;


class Messages
{
    // todo move $translate_data into dictionary
    public static $translate_data = array(
        'sale' => 'Продається',
        'rent' => 'Здається в оренду',
        'flat' => 'квартира',
        'house' => 'будинок',
        'ground' => 'ділянка',
        'commerce' => 'комерція',
        'floor' => 'поверх',
        'square' => 'площа',
        'r' => ' кім.',
        'r+' => ' кім. і більше',
        '1f'        => 'Перший',
        '2f'        => 'Від 2го',
        '4f'        => 'До 4го',
        '5f'        => 'Від 5го',
        '9f'        => 'До 9го',
        '10f'       => 'Від 10го.',
        'mf'        => 'Останній',
        'nmf'       => 'Не останній',
        '1r'        => '1 кім.',
        '12r'       => '1 або 2 кім.',
        '23r'       => '2 або 3 кім.',
    );

    public static function getTittle($type, int $id, $param = null){
        $data = TelegramBaseModel::getThisByTypeID($type, $id, $param);

        $data_type = strtolower(str_replace(['Sale', 'Rent'], '', $param));
//        $data = $model::findFirst("id='" . $id . "'");
        switch ($type) {
            case "user":
                $tittle = $data->first_name . ' ' . $data->last_name;
                break;
            case "showing":
                $date = new \DateTime($data->showing_at);
                $tittle = 'Показ від ' . constant('TG_' . $data->client_type) . '; Час: ' . $date->format('H:i - d/m');
                break;
            case 'reamak':
            case "object":
            case "advertTG":
            case "advert":
                $price = 'price_' . $data->currency;
                $tittle = Messages::$translate_data[$data->category] . ' ' . $data->rooms . 'кім. '
                    .  Messages::$translate_data[$data->type] . ' по вул.' . $data->street
                    . ($data->$price ? ', ' . $data->$price . $data->currency : '');
                break;
            case "request":
                $tittle = $data->search_name;
                break;
            case "favourite":
                $tittle = $data->list_name;
                break;
            case "company":
                $tittle = $data->name;
                break;
            default:{
                $tittle = "Empty tittle";
                break;
            }
        }
        return $tittle ?? "Empty tittle";
    }

    public static function dialogWindow($message, $chat_id, $command)
    {
        $request = Request::sendMessage([
            'chat_id' => $chat_id,
            'text' => '⚠️ ' . $message,
            'parse_mode' => 'HTML',
            'reply_markup' => new InlineKeyboard(...[[
//               Buttons::getButton('✅ ' . constant('TG_YES'), 'inlinekeyboard;answerDialog;_' . $command),
                Buttons::getButton('✅ ' . constant('TG_YES'),  $command),
                Buttons::getButton('❎ ' . constant('TG_NO'), 'inlinekeyboard;close;'),
            ]]),
        ]);
        return $request->isOk() ? $request->getResult()->message_id : false;
    }


    public static function getShowingCardMessage($notes){
        $text = '<b>Показ: </b>'. PHP_EOL ;
        $text .= $notes['client_type'] ? '- від ' . ( $notes['client_type'] == 'owner' ? 'власника' : 'орендаря' ) . PHP_EOL : ' ';
        $text .= isset($notes['is_cooperation']) ? ($notes['is_cooperation'] ? "- через співпрацю" : "- без співпраці") . PHP_EOL : '';
        $text .= $notes['request_id'] ? '<b>Заявка:</b> ' . self::getTittle('request', $notes["request_id"]) . PHP_EOL: ' ';
        $text .= $notes['object_id'] ? '<b>Об\'єкт:</b> ' . self::getTittle('object', $notes['object_id']) . PHP_EOL: ' ';
        $time = new \DateTime( $notes["showing_at"] . $notes["showing_at_time"]);
        $text .= $notes['showing_at'] ? '<b>Час:</b> ' . $time->format('H:i (d/m)') . PHP_EOL: ' ';
        $text .= $notes['description'] ? '<b>Опис:</b> ' . $notes['description'] . PHP_EOL: ' ';
        $text .= $notes['status'] ? '<b>' . TG_STATUS . ':</b> ' . (constant('TG_' . $notes['status']) ?? $notes['status']) . PHP_EOL : '';
        $text .= $notes['accountable_id'] ? '<b>Відповідальний:</b> ' . self::getTittle('user', $notes['accountable_id']) . PHP_EOL: ' ';
        $text .= $notes['result'] ? '<b>' . TG_RESULT . ':</b> ' . (constant('TG_' . $notes['result']) ?? $notes['result']) . PHP_EOL : '';

        return $text;
    }

    public static function getAdvertCardMessage($data, $params = array()){
        //        $realty_data['user_name'] = $this->inline_query->getFrom()->getUsername();

        $price = $data["category"] == "rent" ? $data['price_UAH'] . 'грн' : $data['price_USD'] . '$';

        $text = $params['category'] ? constant('TG_' . strtoupper($params['category'])): '';
        $text .= $data['rooms'] ? ' ' . $data['rooms'] . TG_ROOM_ABB : '';

        $text .= $params['type'] ? ' ' . constant('TG_' . strtoupper($params['type'])) : ' ...';

        $text .= $data['beds'] ? PHP_EOL . '<b>Місць</b>: '  . $data['beds'] : '';

        $text .= $data['is_new'] == 'Yes' ? ', новобуд' : '';
        $text .= $data['city'] || $data['region'] ? PHP_EOL . '<b>Район/Місто: </b>' : '';
        $text .= $data['region'] ? $data['region'] . ' / '  : '';
        $text .= $data['city'] ?  $data['city'] : '';
        $text .= $data['street'] ? PHP_EOL . '<b>Вулиця: </b>' . $data['street'] : '';

        $text .= $data['floor'] || $data['floors'] ? PHP_EOL . '<b>Поверх:</b> ' . ($data['floor'] ?? '-') . '/' . ($data['floors'] ?? '-') : '';
        $square_dwelling = $data['square_dwelling'] ? $data['square_dwelling'] : '-';
        $square_kitchen = $data['square_kitchen'] ? $data['square_kitchen'] : '-';
        $text .= $data['square_full'] ? PHP_EOL . '<b>Площа:</b>  ' . $data['square_full'] . '/' . $square_dwelling . '/' . $square_kitchen . ' м2' : '';
        $text .= $data['square_ground'] ? PHP_EOL . '<b>Площа ділянки:</b>  ' . $data['square_ground'] . 'сот.' : ' ';
        $text .= $data['condition'] ? PHP_EOL . '<b>Стан:</b> ' . $data['condition'] : ' ';
        $text .= $data['wall_material'] ? PHP_EOL . '<b>Матеріал стін:</b> ' . $data['wall_material'] : '';
        if($params["is_full_description"]) {
            $text .= $data['description'] ? PHP_EOL . '<b>Опис:</b> ' . (isset($data['description'][3000]) ? substr(strip_tags($data['description']), 0, 3000) . '...' : strip_tags($data['description'])) : '';
        }else{
            $text .= $data['description'] ? PHP_EOL . '<b>Опис:</b> ' . (isset($data['description'][300]) ? substr(strip_tags($data['description']), 0, 300) . '...' : strip_tags($data['description'])) : '';
        }
//        $text .= $data['latitude'] ? PHP_EOL . 'Координати: ' . substr($data['latitude'], 0, 9) . ', ' . substr($data['longitude'], 0, 9): ' ';
        $text .= $data['photos'] ? PHP_EOL . '<b>Додано:</b> ' . $data['photos'] . ' фото' : ' ';
        $text .= $data['term'] ? PHP_EOL . '<b>Термін:</b> ' . constant('TG_' . $data['term']) : ' ';

        $text .= $price ? PHP_EOL . '<b>Ціна:</b> ' . $price: '';

        if(!$params['resend']){
            $text .= $data['contact_name'] ? PHP_EOL . '<b>Ім\'я:</b> ' . $data['contact_name'] : ' ';
            $text .= (int)$data['contact_phone'] ? PHP_EOL . '<b>Телефон:</b> <a href="tel:+38' . $data['contact_phone'] . '">+38' . $data['contact_phone'] . '</a>' : '';
            $text .= $data['user_name'] ? PHP_EOL . '<b>Username:</b> @' . $data['user_name'] : ' ';
        }
        return $text;
    }

    public static function getAdvertTags($data)
    {
        $_types = array('flat' => 'кв', 'house' => 'буд', 'ground' => 'діл', 'commerce' => 'ком', 'else' => 'ін');
        $type = $_types[$data['type']] ?? $_types['else'];
        $rooms = $data['rooms'] ? $data['rooms'] . 'к' : 0;
        $region = $data['region'] ? mb_substr($data['region'], 0, 5, "UTF-8") : '';

//        $is_new = $data['is_new'] == 'yes' ? 'нов' : '';
        $flore = '';
        if ($data['floor'] && $data['floors']) {
            if ($data['floor'] != 1 && $data['floor'] != $data['floors']) {
                $flore = 'нпо';
            }
        }
//        $object_type = $data['object_type'] ? $data['object_type'] : '';
        if ($data['category'] == 'rent') {
            $_price = $data['price_UAH'];
            $_prices = array(0, 6, 10, 15, 20, 25, 32, 42);
        } else {
            $_price = $data['price_USD'];
            $_prices = array(0, 20, 35, 50, 70, 90, 120, 180, 300, 500);
        }
        $_price = (int)($_price / 1000);

        $price = end($_prices).'_999К';
        foreach ($_prices as $key => $value) {
            if ($_price <= $value) {
                $price = $_prices[$key - 1] . '_' . $_prices[$key] . 'К';
                break;
            }
        }

        $hashtags = ($data['city'] ? '#' . $data['city'] : '')
            . ($data['type'] ? ' #' . constant('TG_' . $data['type']) : '')

            . ($rooms ? ' #' . $type . '_' . $rooms : '')
//            . ($is_new ? ' #' . $type . '_' . $is_new : '')
            . ($price ? ' #' . $type . '_' . $price : '')
            . ($flore ? ' #' . $type . '_' . $flore : '')

            . ($rooms && $price ? ' #' . $type . '_' . $rooms . '_' . $price : '')
            . ($rooms && $flore ? ' #' . $type . '_' . $rooms . '_' . $flore : '')
            . ($price && $flore ? ' #' . $type . '_' . $price . '_' . $flore : '')
            . ($rooms && $price && $flore ? ' #' . $type . '_' . $rooms . '_' . $price . '_' . $flore : '')


            . ($region ? (' #' . $region
                . ($rooms ? ' #' . $region . '_' . $type . '_' . $rooms : '')
                . ($flore ? ' #' . $region . '_' . $type . '_' . $flore : '')
//            . ($is_new ? ' #' . $region . '_' . $type . '_' . $is_new : '')
                . ($price ? ' #' . $region . '_' . $type . '_' . $price : '')

                . ($rooms && $price ? ' #' . $region . '_' . $type . '_' . $rooms . '_' . $price : '')
                . ($rooms && $flore ? ' #' . $region . '_' . $type . '_' . $rooms . '_' . $flore : '')
                . ($price && $flore ? ' #' . $region . '_' . $type . '_' . $price . '_' . $flore : '')
                . ($rooms && $price && $flore ? ' #' . $region . '_' . $type . '_' . $rooms . '_' . $price . '_' . $flore : '')
            ) : '');

//            . ($data["wall_material"] ? ' #' . $data["wall_material"] : '');

        $text = PHP_EOL . PHP_EOL;
        $text .= $hashtags;
        /**
         *   #кв #кв_нов #кв_нпо #кв_40-60
         *   #кв1к #кв1к_нов #кв1к_нпо #кв1к_40-60
         *   #кв1к_нов_нпо #кв1к_нов_40-60
         *   #кв1к_нов_нпо_40-60 #object_type
         */


        return mb_convert_encoding($text, "UTF-8");
    }

    public static function getLeadCardMessage($lead_data){
        $text = ($lead_data->name ? '<b>Ім\'я:</b> ' . $lead_data->name . PHP_EOL: '')
            . ($lead_data->description ? '<b>Опис:</b> ' . $lead_data->description . PHP_EOL: '')
            . '<b>Телефон:</b> +38' . $lead_data->phone . '' . PHP_EOL
            . '<b>Статус: </b>' . constant('TG_' . $lead_data->status) . PHP_EOL
            . '<b>Автор: </b>' .  Messages::getTittle('user', $lead_data->author_id) . PHP_EOL
            . '<b>Відповідальний: </b>' .  ($lead_data->accountable_id ? Messages::getTittle('user', $lead_data->accountable_id) : 'відсутній') . PHP_EOL
            . '<b>Компанія: </b>' . Messages::getTittle('company', $lead_data->company_id) . PHP_EOL
            . '<b>Дата створення: </b>' . $lead_data->added_at . PHP_EOL
            . ($lead_data->contacted_at ? '<b>Дата контакту: </b>' .  $lead_data->contacted_at : '');
        return $text;
    }

    public static function getColdPhoneCardMessage($data){

        $text = 'Контакт:' . PHP_EOL .
            ($data["name"] ? '<b>Ім\'я: </b>' . $data["name"] . PHP_EOL : '') .
            ($data["phone"] ? '<b>Номер: </b>' . $data["phone"] . PHP_EOL : '') .
            ($data["description"] ? '<b>Опис: </b>' . $data["description"] . PHP_EOL : '') .
            ($data["status"] ? '<b>Статус: </b>' . $data["status"] . PHP_EOL : '') .
            ($data["author_id"] ? '<b>Автор: </b>' . self::getTittle('user', $data["author_id"]) . PHP_EOL : '') .
            ($data["accountable_id"] ? '<b>Відповідальний: </b>' . self::getTittle('user', $data["accountable_id"]) . PHP_EOL : '') .
            ($data["added_at"] ? '<b>Додано: </b>' . $data["added_at"] . PHP_EOL : '') .
            ($data["contacted_at"] ? '<b>В опрацюванні з: </b>' . $data["contacted_at"] . PHP_EOL : '');

        return $text;
    }

    public static function getReminderCardMessage($data){
        $time = new \DateTime( $data["reminder_at"]);
        $text = 'Нагадування:' . PHP_EOL .
            ($data["sender_id"] ? '<b>Відправник: </b>' . self::getTittle('user', $data["sender_id"]) . PHP_EOL : '') .
            ($data["receiver"] ? '<b>Отримувач: </b>' .
                ((int)$data["receiver"] ? self::getTittle('user', $data["receiver"]) : constant("TG_" . $data["receiver"])) . PHP_EOL : '') .
            ($data["message"] ? '<b>Текст: </b>' . $data["message"] . PHP_EOL : '') .
            ($data["status"] ? '<b>Статус: </b>' . $data["status"] . PHP_EOL : '') .
            ($data["subject_type"] ? '<b>Категорія: </b>' .
                (defined('TG_' . $data["subject_type"]) ? constant('TG_' . $data["subject_type"]) : $data["subject_type"]) . PHP_EOL : '') .
            ($data['reminder_at'] ? '<b>Час:</b> ' . $time->format('H:i (d/m)') . PHP_EOL: ' ') .
            ($data['period'] ? '<b>Період:</b> ' . constant('TG_' . $data['period']) . PHP_EOL: ' ');
//            ($data["accountable_id"] ? '<b>Відповідальний: </b>' . self::getTittle('user', $data["accountable_id"]) . PHP_EOL : '') .
//            ($data["added_at"] ? '<b>Додано: </b>' . $data["added_at"] . PHP_EOL : '') .
//            ($data["contacted_at"] ? '<b>В опрацюванні з: </b>' . $data["contacted_at"] . PHP_EOL : '');

        return $text;
    }

    public static function sendMeMessage($text){
        return self::sendMessage(522625209, $text);
    }

    public static function sendMessage($user_id, $text){
        $request = Request::sendMessage([
            'chat_id' => $user_id,
            'text' =>  $text,
            'parse_mode' => 'HTML'
        ]);
        if(!$request->isOk()){
            Request::sendMessage([
                'chat_id' => $user_id,
                'text' =>   'Повідомлення не надіслано:' . PHP_EOL .
                    $request->getDescription(),
            ]);
        }
        return $request;
    }

    public static function sendCallbackAnswer($callback_id, $message, $alert = false){
        Request::answerCallbackQuery(
            array(
                'callback_query_id' => $callback_id,
                'text' => $message,
                'show_alert' => $alert,
            )
        );
    }

    public static function sendInfoMessage($chat_id, $message, int $time = 4)
    {
        return self::sendDataMessage(
            [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'HTML'
            ],
            $time);
    }

    public static function sendDataMessage($data, $time = 0)
    {
        if($data["message_id"]){
            $info_message = Request::editMessageText($data);
        }else{
            $info_message = Request::sendMessage($data);
        }

        if($time) {
           sleep($time);
           Request::deleteMessage([
               'chat_id' => $info_message->getResult()->getChat()->id,
               "message_id" => $info_message->getResult()->message_id
           ]);
       }
        return $info_message;
    }

    public static function getModelSaveErrText($object){
        return implode('; ', $object->getMessages());
    }
}