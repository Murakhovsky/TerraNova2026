<?php

namespace Modules\TgAdmin\Renderer;

use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\KeyboardButton;
use DateTime;
use Longman\TelegramBot\Request;
use Modules\Users\Models\Company\Employees as TelegramEbEmployees;
use TelegramModels\Finance\TelegramFinMoney;


class Buttons{

    public static function getButton($text, $data, $type = 'cd')
    {
        $type = $type ?: 'cd';
        //TODO add exception
        if(!$text || !$data){ return false; }

        $inline_types = array('cd' => 'callback_data', 'sc' => 'switch_inline_query_current_chat', 's' => 'switch_inline_query');
        $types = array('rc' => 'request_contact', 'rl' => 'request_location');
        $button = array( 'text' => mb_convert_encoding($text, 'UTF-8'));
        if($inline_types[$type]){
            $button[$inline_types[$type]] = $data;
            $model = InlineKeyboardButton::class;
        }elseif($types[$type]){
            $button[$types[$type]] = $data;
            $model = KeyboardButton::class;
        }else{
            return false;
        }
        return new $model($button);
    }
    public static function getPageButtons(string $command, string $page):array
    {
        $page_data = self::get_pages_data($command, $page);
        $buttons_page = array();
        foreach ( $page_data as $line){
            $buttons_line = array();
            foreach ( $line as $button_data){
                $buttons_line[] = self::getButton(Translator::t($button_data[0]),$button_data[1],$button_data[2]);
            }
            $buttons_page[] = $buttons_line;
        }

        return $buttons_page;
    }
    public static function getInlineKeyboard( $keyboard_data)
    {
        return (new InlineKeyboard(...$keyboard_data))->setResizeKeyboard(true);
        //        ->setOneTimeKeyboard(true)
//            ->setSelective(true);
    }
    public static function getKeyboard( $keyboard_data)
    {
        $te = (new Keyboard(...$keyboard_data))->setResizeKeyboard(true);
        return $te;
    }
    public static function getSurveyMenu($command, $page, $page_val){
        $inline_keyboard = array();
        $inline_keyboard_arr = array();
        $optional_list = self::get_optional_list();

        if($page != 'start') {
//        if(count(json_decode($this->notes['pre_state'])) != 1) {
            $inline_keyboard_arr[] = self::getButton('⬅ Назад',$command . ';command;prev',);
        }

        if (isset($page_val) && isset($optional_list[$page])){
            $inline_keyboard_arr[] = self::getButton('🚮 Очистити',$command . ';command;clear',);
        }
//        Messages::sendMeMessage(
//        (isset($page_val) ? "true, " : "false, ") .
//            (isset($optional_list[$page]) ? "true, " : "false, ") .
//            $page
//        );
        if ((isset($page_val) || isset($optional_list[$page])) && $page!='end') {
            $inline_keyboard_arr[] = self::getButton('➡ Вперед',$command . ';command;next',);
        }
        if($command == 'request') {
            $inline_keyboard_arr[] = self::getButton('▶ Пошук','request;command;search',);
        }
        $inline_keyboard_arr[] = self::getButton('⏹ Вихід', $command . ';command;dialog;exit',);

        switch (count($inline_keyboard_arr)) {
            case 1:
            case 2:
            case 3:
                $inline_keyboard = array($inline_keyboard_arr);
                break;
            case 4:
                $inline_keyboard[] = array($inline_keyboard_arr[0], $inline_keyboard_arr[1]);
                $inline_keyboard[] = array($inline_keyboard_arr[2], $inline_keyboard_arr[3]);
                break;
            case 5:
                $inline_keyboard[] = array($inline_keyboard_arr[0], $inline_keyboard_arr[1], $inline_keyboard_arr[2]);
                $inline_keyboard[] = array($inline_keyboard_arr[3], $inline_keyboard_arr[4]);
                break;
        }

        return $inline_keyboard;
    }
    static function getCheckBox($text, $data, $is_checked = false){
        $tittle = ($is_checked ? '🟩' : '🔲') . ' ' . $text;
        return self::getButton($tittle, $data);
    }

    static function getRadioButton($text, $data, $is_checked = false){
        $tittle = ($is_checked ? '🟢' : '⚪') . ' ' . $text;
        return self::getButton($tittle, $data);
    }

    static function getNavigation(array $data, $second_button = false, $third_button = false):array{
        $key = 1;

        $nav_menu_buttons = array(
            new InlineKeyboardButton(array(
                'text' => '⏪ ' . TG_MENU,
                'callback_data' => 'menu;main',
            ))
        );

        if( $second_button ){
            $second_button->text = '⬅ ' . $second_button->text;
            $nav_menu_buttons[] = $second_button;
            $key++;
        }

        if( $third_button ){
            $third_button->text = '⬅ ' . $third_button->text;
            $nav_menu_buttons[] = $third_button;
            $key++;
        }

        $secondary_buttons = array(
            self::getButton('⚔️ ' . $data["xp"] . ' XP', 'inlinekeyboard;inProgress'),
            self::getButton('🏅 ' . $data["level"] . ' LV', 'inlinekeyboard;inProgress'),
            self::getButton('🔔 ' . $data["msg"], 'inlinekeyboard;inProgress'),
            self::getButton('👤', 'menu;profile'),
        );

        for($i=$key;$i<count($secondary_buttons);$i++){
            $nav_menu_buttons[] = $secondary_buttons[$i];
        }

        return $nav_menu_buttons;
    }
    static function getMainMenu(array $data):array{
        return array(
            self::getButton('⚔️ ' . $data["xp"] . ' XP', 'inlinekeyboard;inProgress'),
            self::getButton('🏅 ' . $data["level"] . ' LV', 'inlinekeyboard;inProgress'),
            self::getButton('🔔 ' . $data["msg"], 'inlinekeyboard;inProgress'),
            self::getButton('💰 ' . $data["coin"] . ' $',  'menu;wallet'),
        );
    }

    static function get_like_line($clicked = 0, $user_id){
        return array(
            self::getButton( $clicked == 1 ? '🖕' : '🖕🏻','inlinekeyboard;change_personal_rating;1,' . $user_id),
            self::getButton($clicked == 2 ? '👎' : '👎🏻','inlinekeyboard;change_personal_rating;2,' . $user_id),
            self::getButton($clicked == 3 ? '☝' : '☝🏻','inlinekeyboard;change_personal_rating;3,' . $user_id),
            self::getButton($clicked == 4 ? '👍' : '👍🏻','inlinekeyboard;change_personal_rating;4,' . $user_id),
            self::getButton($clicked == 5 ? '🤘' : '🤘🏻','inlinekeyboard;change_personal_rating;5,' . $user_id),
        );
    }

    static function getEmployerLine($company_id, $user_data){

        if (!$user_data->company_id) {return false;}

        $employees = TelegramEbEmployees::findFirst('user_id = "' . $user_data->id . '" AND company_id = "' . $company_id . '"');

        if ($employees){
            return array(
                self::getButton('Звільнити','inlinekeyboard;dismissUserFromCompany;' . $user_data->id),
                self::getButton(TG_STATUS, 'inlinekeyboard;changeStatus;show;employees;' . $employees->id . ';test'),
//                self::getButton('Змінити', 'inlinekeyboard;infoWindow;inProgress')
            );
        }else{
            return array(
                self::getButton('Найняти','inlinekeyboard;addUserToCompany;' . $user_data->id),
                self::getButton('Відхилити','inlinekeyboard;rejectUserForCompany;' . $user_data->id),
            );
        }

        return false;
    }

    static function getAdminLine($type, $id){
        return $inline_keyboard[] = array(
//            self::getButton('🕓 ' . TG_ADD_REMINDER,  'setreminder;command;start;' . $type . ';' . $id),
            self::getButton('🖋 ' . TG_EDIT, $type . ';command;edit;' . $id),
            self::getButton('🗑 ' . TG_DELETE, 'inlinekeyboard;delete;' . $type . ';' . $id)
        );
    }

    static function getControlLine($params){
        return array(
            self::getButton('⏫ ' . TG_COLLAPSE, 'inlinekeyboard;collapse;' . $params),
            self::getButton('❌ ' . TG_CLOSE, 'inlinekeyboard;close;'),
        );
    }
//    static function getCalendar(){
//        $today = date();
//        $first_day = new DateTime(date('Y-m-') . '01');
//        $day = $first_day->format('l');
//        return array(
//            self::getButton('⏫ ' . $day, 'inlinekeyboard;collapse;'),
//
//        );
//    }
    static function getCloseButton(){
        return array(self::getButton('❌ ' . TG_CLOSE, 'inlinekeyboard;close;'));
    }

    static function getCalendar(int $month, int $year, $command, $page): array {
        $prevMonthCallback = 'calendar-month-';
        if ($month === 1) {
            $prevMonthCallback .= '12-'.($year-1);
        } else {
            $prevMonthCallback .= ($month-1).'-'.$year;
        }

        $nextMonthCallback = 'calendar-month-';
        if ($month === 12) {
            $nextMonthCallback .= '1-'.($year+1);
        } else {
            $nextMonthCallback .= ($month+1).'-'.$year;
        }

        $start = new DateTime(sprintf('%d-%d-01', $year, $month));

        $calendarMap = [
            [
                ['text' => '⏪', 'callback_data' => $command . ';command;calendarCallback;' . $prevMonthCallback],
                ['text' => $start->format('m/Y'), 'callback_data' => 'calendar-months_list-'.$year],
                ['text' => '⏩', 'callback_data' => $command . ';command;calendarCallback;' . $nextMonthCallback],
            ],
            [
                ['text' => 'Пн', 'callback_data' => 'null_callback'],
                ['text' => 'Вт', 'callback_data' => 'null_callback'],
                ['text' => 'Ср', 'callback_data' => 'null_callback'],
                ['text' => 'Чт', 'callback_data' => 'null_callback'],
                ['text' => 'Пт', 'callback_data' => 'null_callback'],
                ['text' => 'Сб', 'callback_data' => 'null_callback'],
                ['text' => 'Нд', 'callback_data' => 'null_callback'],
            ],
        ];


        $end = clone $start;
        $end->modify('last day of this month');
        $iterEnd = clone $start;
        $iterEnd->modify('first day of next month');
        $row = 2;
        foreach (new \DatePeriod($start, new \DateInterval("P1D"), $iterEnd) as $date) {
            /** @var \DateTime $date */

            if (!isset($calendarMap[$row])) {
                $calendarMap[$row] = array_combine([1, 2, 3, 4, 5, 6, 7], [[], [], [], [], [], [], []]);
            }

            $dayIterator = (int)$date->format('N');
            if ($dayIterator != 1 && $start->format('d') === $date->format('d')) {
                for ($i = 1; $i < $dayIterator; $i++){
                    $calendarMap[$row][$i] = ['text' => ' ', 'callback_data' => 'null_callback'];
                }
            }

            $calendarMap[$row][$dayIterator] = [
                'text' => $date->format('d'),
                'callback_data' => $command . ';' . $page . ';'
                    . sprintf('%d-%s-%s', $year, $date->format('m'), $date->format('d'))
            ];

            if ($dayIterator < 7 && $end->format('d') === $date->format('d')) {
                for ($i = $dayIterator+1; $i <= 7; $i++){
                    $calendarMap[$row][$i] = ['text' => ' ', 'callback_data' => 'null_callback'];
                }
                $calendarMap[$row] = array_values($calendarMap[$row]);
                break;
            }

            if ($dayIterator === 7) {
                $calendarMap[$row] = array_values($calendarMap[$row]);
                $row++;
            }
        }

        return $calendarMap;
    }

    function get_months_list(int $year): array {
        $listMap = [
            [
                ['text' => '<', 'callback_data' => 'calendar-year-'.($year-1)],
                ['text' => $year, 'callback_data' => 'calendar-years_list-'.$year],
                ['text' => '>', 'callback_data' => 'calendar-year-'.($year+1)],
            ],
        ];

        $row = 1;

        for($month = 1; $month <= 12; $month++) {
            $listMap[$row][] = ['text' => date('F', strtotime(sprintf('%d-%d-01', $year, $month))), 'callback_data' => sprintf('calendar-month-%d-%d', $month, $year)];

            if ($month === 3 || $month === 6 || $month === 9) {
                $row++;
            }
        }

        return $listMap;
    }

    function get_years_list(int $centerYear): array {
        $prevYear = $centerYear-25;
        $nextYear = $centerYear+25;
        $listMap = [
            [
                $prevYear <= 76 ? ['text' => ' ', 'callback_data' => 'null_callback'] : ['text' => '<', 'callback_data' => 'calendar-years_list-'.$prevYear],
//            ['text' => ' ', 'callback_data' => 'null_callback'],
                $nextYear >= 10024 ? ['text' => ' ', 'callback_data' => 'null_callback'] : ['text' => '>', 'callback_data' => 'calendar-years_list-'.$nextYear],
            ],
        ];

        $row = 1;
        $i = 0;

        for ($year = ($centerYear - 12); $year <= ($centerYear+12); $year++) {
            if ($year >= 100 && $year <= 9999) {
                $listMap[$row][] = ['text' => $year, 'callback_data' => sprintf('calendar-months_list-%d', $year)];
                $i++;
            } else {
//            $listMap[$row][] = ['text' => ' ', 'callback_data' => sprintf('calendar-months_list-%d', $year)];
            }

            if ($i === 5 || $i === 10 || $i === 15 || $i === 20) {
                $row++;
            }
        }


        return $listMap;
    }

    public static function removeKey()
    {
        return Keyboard::remove();
    }
    private static function get_optional_list(): array{
        return array(
            // Requests
            'region' => true,
            'square' => true,
            'floor' => true,
            'description' => true,
            'square_ground' => true,

            // Objects
            'add_description' => true,
            'square_dwelling' => true,
            'square_kitchen' => true,
            'photos' => true,
        );
    }
    private static function get_pages_data($command, $page): array
    {
        $pages_data = array(
            "category" => [[
                [Translator::t("TG_RENT"), $command . ';category;rent'],
                [Translator::t("TG_SALE"), $command . ';category;sale']
            ]],
            "type" => [[
                [Translator::t("TG_FLAT"),$command . ';type;flat',],
                [Translator::t("TG_HOUSE"),$command . ';type;house',]
            ],[
                [Translator::t("TG_LAND"), $command . ';type;land',],
                [Translator::t("TG_COMMERCE"), $command . ';type;commerce',]
            ]],
            "is_new" => [[
                [Translator::t("TG_YES"), $command . ';is_new;yes',],
                [Translator::t("TG_NO"), $command . ';is_new;no',]
            ]],
            "is_private" => [[
                ['Ні',$command . ';is_private;no',],
                ['Так',$command . ';is_private;yes',],
            ]],
            "object_type" => array(
                "flat" => [[
                    ['Чешка',$command . ';object_type;Чешка'],
                    ['Хрущовка',$command . ';object_type;Хрущовка'],
                    ['Австрійка',$command . ';object_type;Австрійка'],
                ], [
                    ['Сталінка',$command . ';object_type;Сталінка'],
                    ['Малосімейка',$command . ';object_type;Малосімейка'],
                ],[
                    ['Житловий фонд 91-00',$command . ';object_type;Житловий фонд 91-00'],
                    ['Житловий фонд 00-13',$command . ';object_type;Житловий фонд 00-13'],
                ]],
                "house" => [[
                    ['Окремостоячий',$command . ';object_type;Окремостоячий'],
                    ['Спарка',$command . ';object_type;Спарка'],
                ], [
                    ['Котедж',$command . ';object_type;Котедж'],
                    ['Дача',$command . ';object_type;Дача'],
                ]],
                "commerce" => [[
                    ['Офіс',$command . ';object_type;Офіс'],
                    ['Магазин',$command . ';object_type;Магазин'],
                ], [
                    ['Склад',$command . ';object_type;Склад'],
                    ['Виробництво',$command . ';object_type;Виробництво'],
                ]],
                "ground" => [[
                    ['Під будівництво',$command . ';object_type;Під будівництво'],
                    ['Під СГ',$command . ';object_type;Під СГ'],
                ]]
            ),
            "condition" => [
                [
                    ['0-цикл',$command . ';condition;0-цикл'],
                    ['потребує ремонту',$command . ';condition;потребує ремонту'],
                ], [
                    ['житловий стан',$command . ';condition;житловий стан'],
                    ['косметичний ремонт',$command . ';condition;косметичний ремонт'],
                ], [
                    ['євроремонт',$command . ';condition;євроремонт'],
                    ['дизайнерський ремонт',$command . ';condition;дизайнерський ремонт'],
                ]],
            "wall_material" => [
                [
                    ['цегла',$command . ';wall_material;цегла'],
                    ['панель',$command . ';wall_material;панель'],
                ], [
                    ['газоблок',$command . ';wall_material;газоблок'],
                    ['бетон',$command . ';wall_material;бетон'],
                ], [
                    ['дерево',$command . ';wall_material;дерево'],
                    ['піноблок',$command . ';wall_material;піноблок'],
                ]],
            "city" => [
                [
//                    ['Київ',$command . ';city;Kyiv',],
                    ['Львів',$command . ';city;Lviv',],
                    ['Брюховичі',$command . ';city;Bruhovychi',],
                ],                [
                    ['Дубляни',$command . ';city;Dubliany',],[
                    'Малехів',$command . ';city;Malehiv',
                ]],                [
                    ['Винники',$command . ';city;Vynnyky',],[
                    'Лисиничі',$command . ';city;Lysynychi',]
                ],[
                    ['Зубра',$command . ';city;Zubra',],
                    ['Солонка',$command . ';city;Solonka',]
                ],[
                    ['Сокільники',$command . ';city;Sokilnyky',],
                    ['Скнилів',$command . ';city;Sknyliv',]
                ],[
                    ['Пустомити',$command . ';city;Pustomyty',],
                    ['Рудне',$command . ';city;Rudne',]
                ],[
                    ['Зимна вода',$command . ';city;Zymnavoda',],
                    ['Лапаївка',$command . ';city;Lapaivka',],
                ],
//                [
//                    [
//                        'Суховоля',
//                        'callback_data' => $command . ';city;Sukhovolia',
//                    ],
//                    [
//                        'Зимна вода',
//                        'callback_data' => $command . ';city;Zymnavoda',
//                    ]
//                ]
            ],
            "region" => array(
                "Lviv" => [
                    [
                        ['Галицький',$command . ';region;galician',],
                        ['Шевченківський',$command . ';region;shevchenkivsky',],],
                    [
                        ['Франківський',$command . ';region;frankivsky',],
                        ['Сихівський',$command . ';region;sykhivsky',]
                    ],[
                        ['Залізничний',$command . ';region;zaliznychny',],
                        ['Личаківський',$command . ';region;lychakivsky',                        ]
                    ]],
                "Kyiv" => [

                    [
                        ['Голосіївський',$command . ';region;golosiivsky',],
                        ['Дарницький',$command . ';region;darnitsky',]
                    ],[
                        ['Деснянський',$command . ';region;desniansky',],
                        ['Дніпровський',$command . ';region;dniprovsky',
                        ]],
                    [
                        ['Оболонський',$command . ';region;obolonsky',],
                        ['Печерський',$command . ';region;pechersky',],],
                    [
                        ['Подільський',$command . ';region;Podolsky',],
                        ['Святошинський',$command . ';region;Sviatoshynsky',],],
                    [
                        ['Cолом\'янський',$command . ';region;Solomiansky',],
                        ['Шевченківський',$command . ';region;shevchenkivsky',],],
                ],
            ),
            "location" => [[['Надіслати поточне розташування', true, 'rl']]],
            "contact_phone" => [[['Надіслати мій номер', true, 'rc']]],
            "contact_name" => [[[Translator::t("TG_KEEP_CURRENT_NAME"), $command . ';contact_name;current name',]]],
            "end" => [[[Translator::t("TG_SAVE"), $command . ';command;end']]],
            "rooms" => array(
                "object" => [[
                    ['1к', $command . ';rooms;1r',],
                    ['2к', $command . ';rooms;2r',],
                    ['3к', $command . ';rooms;3r',],
                    ['3к', $command . ';rooms;3r',],
                    ['4к', $command . ';rooms;4r',],
                    ['5к+', $command . ';rooms;5r',]
                ]],
                "request" => [[
                    ['1к','request;rooms;1 1',],
                    ['1-2к','request;rooms;1 2',],
                    ['2-3к','request;rooms;2 3',],
                    ['3к+','request;rooms;3 9',]
                ]]),
            "floor" => [[
                [
                    'Не перший',$command . ';floor;2 40'
                ],
                [
                    'Від 5го',$command . ';floor;5 40'
                ],
                [
                    'Від 10го',$command . ';floor;10 40'
                ],
                [
                    'Останній',$command . ';floor;41'
                ]
            ],[
                [
                    'Перший',$command . ';floor;1 1'
                ],
                [
                    'До 4го',$command . ';floor;1 4'
                ],
                [
                    'До 9го',$command . ';floor;1 9'
                ],
                [
                    'Не останній',$command . ';floor;1 41'
                ]
            ]],
            "floors" => [[
                [
                    'Перший',$command . ';floors;1f'
                ],
                [
                    'До 4го',$command . ';floors;4f'
                ],
                [
                    'До 7го',$command . ';floors;7f'
                ],
                [
                    'До 10го',$command . ';floors;10f'
                ],
                [
                    'Не останній',$command . ';floor;nmf'
                ],
            ]],
            "object_rooms" => [[
                ['1к',$command.';rooms;1',],
                ['2к',$command.';rooms;2',],
                ['3к',$command.';rooms;3',],
                ['4к',$command.';rooms;4',],
                ['5к+',$command.';rooms;5',]
            ]],
            "photo" => [[['Фото профілю', $command . ';command;addProfilePhoto']]],
            "where_from" => [[
                    [ "lviv", $command . ';where_from;lviv',],
                    [ "kyiv", $command . ';where_from;kyiv',],
                ],[
                    ["vinnitsa", $command . ';where_from;vinnitsa',],
                    ["odessa", $command . ';where_from;odessa',],
            ]],
        );
        $page_arr = explode("/", $page);

        if(!$pages_data[$page_arr[0]]) return array();
        return  isset($page_arr[1]) ?
            $pages_data[$page_arr[0]][$page_arr[1]] :
            $pages_data[$page_arr[0]];
    }
}
