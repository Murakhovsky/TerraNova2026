<?php


namespace Modules\TgAdmin\Models\Request;


use Modules\TgAdmin\Models\Featuring\PresentationModel;
use Longman\TelegramBot\Entities\InlineKeyboard;

use Modules\TgAdmin\Featuring\Buttons;
use Modules\TgAdmin\Models\Service\Lists;

class Requests extends PresentationModel
{
    public $search_id;
    public $search_name;
    public $category;
    public $type;
    public $is_new;
    public $object_type;
    public $user_id;
    public $rooms_from;
    public $rooms_to;
    public $floor_from;
    public $floor_to;
    public $floors;
    public $square_from;
    public $square_to;
    public $square_ground_from;
    public $square_ground_to;
    public $condition;
    public $wall_material;
    public $price_from;
    public $price_to;
    public $description;
    public $city;
    public $region;
    public $date;
    public $contacted_at;
    public $contact;
    public $status;
    public $author_id; //employee_id
    public $accountable_id; //employee_id
    public $company_id;
    public $lead_id;
    public $chat_id;
    public $source;
    public $is_private;
    public $is_archive;


    public function initialize()
    {
        $this->setSource('request_requests');
    }


    public function setDataFromConversation($notes){
        parent::setDataFromConversation($notes);
        $this->is_new = $notes["is_new"] ? 1 : 0;
        $this->date = date('Y-m-d H:i:s');
        $this->is_archive = false;
    }


    public function getTGCard(int $user_id, array $params)
    {
        $text = $this::getTGText((array)$this, $params);

        $inline_keyboard = array(
            array(
                Buttons::getButton('Знайти', $this->search_id, 'switch_inline_query_current_chat'),
                Buttons::getButton('Знайти  ⤴', $this->search_id, 'switch_inline_query')
            ),
            array(
                Buttons::getButton('Статус', 'inlinekeyboard;inProgress'),
                Buttons::getButton(TG_ADD_OBJECT,'inlinekeyboard;inProgress')
            ),
            array(
                Buttons::getButton(TG_ADD_TO_LIST, 'inlinekeyboard;inProgress'), //'inlinekeyboard;setFavourite;getList;' . $realty_search_data->id]),
                Buttons::getButton('Додати нагадування', 'inlinekeyboard;inProgress')
            ),
            array(
//                Buttons::getButton('🗒 ' . TG_OBJECTS, 'inlinekeyboard;inProgress'),
                Buttons::getButton('🔑 Створити показ', 'showing;command;start;request_id;' . $this->id),
                Buttons::getButton('🔑 Запит на показ', 'inlinekeyboard;inProgress;'),
            )
        );

        if ($user_id == $this->user_id) {
            $inline_keyboard[] = Buttons::getAdminLine('request', $this->id);

            $text .= $this->is_private ? PHP_EOL . '<b>Приватна:</b> ' . self::const($this->is_private) : ' ';

            $favourite_str = '';
            $favourite_list = ListsItems::find(
                "item_id = '" . $this->id . "'"
            );
            if (count($favourite_list)) {
                foreach ($favourite_list as $favourite) {
                    $favourite = Lists::findFirst(
                        "id = '" . $favourite->list_id . "'"
                    );
                    $favourite_str .= $favourite->list_name . '; ';
                }
            }
        }
        $inline_keyboard[] = Buttons::getControlLine('request,' . $this->id);

        return array(
            'message_text' => mb_convert_encoding($text, "UTF-8"),
            'reply_markup' => new InlineKeyboard(...$inline_keyboard)
        );
    }
    public static function getTGText(array $data, array $params = array())
    {
        $text = '<b>Заявка: </b>' . '<b>' . $data['search_name'] . '</b>' . PHP_EOL . PHP_EOL;
        $text .= ($data['category'] ? self::const($data['category']) . PHP_EOL : '');
//        $text .= ($data['category'] == 'sale' ? TG_BY : TG_RENT) . '. ';
        $text .= ($data['rooms_from'] ? ' <b>' . $data['rooms_from'] . '</b>' . ($data['rooms_to'] ? '-<b>' . $data['rooms_to'] . '</b>' : '') . 'кім. ' : '') .self::const($data['type']);
        $text .= $data['is_new'] == 'Yes' ? ', новобуд' : '';
        $text .= $data['region'] ? PHP_EOL . '<b>Район: </b>' . self::const($data['region']) . ' ('  . self::const($data['city']) . ')': '';
        $text .= $data['floor_from'] || $data['floor_to'] ? PHP_EOL . '<b>Поверх:</b> '
            . ($data['floor_from'] == '41' ? 'останній' : ($data['floor_from'] == '1' ? '' : ' від <b>' . $data['floor_from'] . '</b>' ))
            . (!$data['floor_to'] || $data['floor_to'] == 40? '' : ($data['floor_to'] == '1' ? 'перший' : ($data['floor_to'] == '41' ? 'не останній' : ' - до <b>' . $data['floor_to'] . '</b>'))) : '';

        $text .= $data['square_from'] || $data['square_to'] ? PHP_EOL . '<b>Площа: </b>'
            . ($data['square_from']? '  від <b>' . $data['square_from'] . 'м2</b>' : '')
            . ($data['square_to'] ? '  -  до: <b>' . $data['square_to'] . 'м2</b>' : '') : '';
        $text .= $data['square_ground_from'] || $data['square_ground_to'] ? PHP_EOL . '<b>Площа ділянки: </b>'
            . ($data['square_ground_from']? '  від <b>' . $data['square_ground_from'] . 'сот.</b>' : '')
            . ($data['square_ground_to'] ? '  -  до: <b>' . $data['square_ground_to'] . 'сот.</b>' : '') : '';
        $text .= $data['price_from'] || $data['price_to'] ? PHP_EOL . '<b>Вартість: </b>' : '';
        $text .= $data['price_from'] ? ' - від <b>' . $data['price_from'] . $data['currency'].'</b>' : '';
        $text .= $data['price_to'] ? ' - до <b>' . $data['price_to'] . $data['currency'].'</b>' : '';
        $text .= $data['description'] ? PHP_EOL . '<b>Опис:</b> ' . $data['description'] : '';
        $text .= $data['contact'] ? PHP_EOL . '<b>Номер:</b> ' . $data['contact'] : '';
//        $text .= $data['is_private'] ? PHP_EOL . '<b>Приватна:</b> ' . self::const($data['is_private']) : ' ';
        $text .= $data['date'] ? PHP_EOL . '<b>Створено:</b> ' . $data['date'] : '';
        return $text;
    }
    public static function getTittle(array $data)
    {
        return $data["search_name"] ?? 'без назви';
    }
    public function getDescription()
    {
        return ($this->rooms_from ?? '')
            . ($this->rooms_to ?  '-' . $this->rooms_to : '+') . 'кім '
            . ($this->object_type ?? (self::const($this->type) ?? $this->type))
            . ($this->region ? ', ' . (self::const($this->region) ?? $this->region) . ' р-н.' : '')
            . ($this->street ? ', по вул.' . $this->street : '')
            . ($this->floor ? PHP_EOL . 'Поверх:  ' . $this->floor : '')
            . ($this->square_from || $this->square_to ? ';   Площа:' : '')
            . ($this->square_from ? ' - від ' . $this->square_from . 'м2' : '')
            . ($this->square_to ? ' - до ' . $this->square_to . 'м2' : '')
            . ($this->price_from || $this->price_to ? PHP_EOL . 'Вартість:' : '')
            . ($this->price_from ? ' - від ' . $this->price_from . $this->currency . '' : '')
            . ($this->price_to ? ' - до ' . $this->price_to . $this->currency . '' : '');
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

    public static function getCountOf($company_id, $status = 'in_work', $user_id = false, $period = 'all', $interval = false){

        $data = array();
        $condition = 'company_id = :c_id:';
        $bind = array(
            'c_id' => $company_id
        );
        if($status && $status != 'all'){
            if ($status == '_group_'){
                $data['group'] = 'status';
            }else {
                $condition .= ' AND status = :s:';
                $bind['s'] = $status;
            }
        }
        if($user_id){
            $condition .= ' AND accountable_id = :a_id:';
            $bind['a_id'] = $user_id;
        }
        if($period && $period != 'all'){
//            $stop_date = '2009-09-30 20:24:00';
            $stop_date = date('Y-m-d H:i:s', strtotime(' -1 ' . $period));
            $condition .= ' AND date > :c_at:';
            $bind['c_at'] = $stop_date;
        }
        if($interval){
            $interval_int = explode('_', $interval);
            $condition .= ' AND price_to >= :p_from:';
            $bind['p_from'] = (int)$interval_int[0];
            if(isset($interval_int[1])){
                $condition .= ' AND price_to < :p_to:';
                $bind['p_to'] = (int)$interval_int[1];
            }
        }
//        var_dump($condition);
//        var_dump(json_encode($bind));

        $result = self::count([
                $condition,
                'bind' => $bind,
            ] + $data);
//        var_dump($result);
//        exit();
        return $result;
    }

    public static function getByPeriod($company_id, $status = 'in_work', $user_id = false, $period = 'all'){

        $data = array();
        $condition = 'company_id = :c_id:';
        $bind = array(
            'c_id' => $company_id
        );
        if($status){
            $condition .= ' AND status = :s:';
            $bind['s'] = $status;
        }else{
            $data['group'] = 'status';
        }
        if($user_id){
            $condition .= ' AND accountable_id = :a_id:';
            $bind['a_id'] = $user_id;
        }
        if($period != 'all'){
//            $stop_date = '2009-09-30 20:24:00';
            $stop_date = date('Y-m-d H:i:s', strtotime(' -1 ' . $period));
            $condition .= ' AND date > :c_at:';
            $bind['c_at'] = $stop_date;
        }

        return self::find([
                $condition,
                'bind' => $bind,
            ] + $data);
    }

    public static function getForTelegram($company_id, $status = 'in_work', $user_id = false, $period = 'all'){

        $data = array();
        $columns = ["id", "search_name", "category", "type", "is_new",
            "object_type", "rooms_from", "rooms_to", "floor_from", "floor_to",
            "square_from", "square_to", "square_ground_from", "square_ground_to",
            "condition", "wall_material", "price_from", "price_to", "description",
            "city", "region", "date", "contacted_at", "contact",
            "status", "author_id", "accountable_id", "company_id", "lead_id", "chat_id"];
//            'is_private, is_archive;';
        $condition = 'company_id = :c_id:';
        $bind = array(
            'c_id' => $company_id
        );
        if($status && $status != 'all'){
            $condition .= ' AND status = :s:';
            $bind['s'] = $status;
        }else{
//            $data['group'] = 'status';
        }
        if($user_id){
            $condition .= ' AND accountable_id = :a_id:';
            $bind['a_id'] = $user_id;
        }
        if($period != 'all'){
//            $stop_date = '2009-09-30 20:24:00';
            $stop_date = date('Y-m-d H:i:s', strtotime(' -1 ' . $period));
            $condition .= ' AND contacted_at > :c_at:';
            $bind['c_at'] = $stop_date;
        }

        return self::find([
            "columns" => $columns,
            $condition,
            'bind' => $bind,
            'order' => 'contacted_at DESC'
        ] + $data);
    }
}