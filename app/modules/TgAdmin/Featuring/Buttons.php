<?php

namespace Modules\TgAdmin\Featuring;

use Longman\TelegramBot\Entities\InlineKeyboardButton;
use DateTime;
use Longman\TelegramBot\Request;
use TelegramModels\Finance\TelegramFinMoney;

class Buttons{

    static function getButton($text, $data, $type = 'callback_data'){
        //TODO add exception
        if(!$text || !$data){ return false; }

        $types = array('sc' => 'switch_inline_query_current_chat', 's' => 'switch_inline_query');
        return new InlineKeyboardButton(array(
            'text' => defined($text) ? constant($text) : $text,
            $types[$type] ?? $type => $data
        ));
    }

    static function getStaticButton($text, $data, $type = 'callback_data'){
        //TODO add exception
        if(!$text || !$data){ return false; }

        $types = array('sc' => 'switch_inline_query_current_chat', 's' => 'switch_inline_query');
        return new InlineKeyboardButton(array(
            'text' => defined($text) ? constant($text) : $text,
            $types[$type] ?? $type => $data
        ));
    }

    static function getCheckBox($text, $data, $is_checked = false){
        $tittle = ($is_checked ? '🟩' : '🔲') . ' ' . $text;
        return self::getButton($tittle, $data);
    }

    static function getRadioButton($text, $data, $is_checked = false){
        $tittle = ($is_checked ? '🟢' : '⚪') . ' ' . $text;
        return self::getButton($tittle, $data);
    }

    static function getNavigation($second_button = false, $third_button = false):array{
        $nav_menu_buttons = array(
            new InlineKeyboardButton(array(
                'text' => '⏪ ' . TG_MENU,
                'callback_data' => 'menu;main',
            ))
        );

        if( $second_button ){
            $second_button->text = '⬅ ' . $second_button->text;
            $nav_menu_buttons[] = $second_button;
        }

        if( $third_button ){
            $third_button->text = '⬅ ' . $third_button->text;
            $nav_menu_buttons[] = $third_button;
        }
//        $nav_menu_buttons[] = Buttons::getButton('🔔 10', 'inlinekeyboard;inProgress');
        $nav_menu_buttons[] = Buttons::getButton('🏅 20LV', 'inlinekeyboard;inProgress');
        $nav_menu_buttons[] =  Buttons::getButton('⚔️ 100XP', 'inlinekeyboard;inProgress');
        return $nav_menu_buttons;
    }
    static function getMainMenu($main_menu_buttons):array{
        return array(
            Buttons::getButton('🔔 10', 'inlinekeyboard;inProgress'),
            Buttons::getButton('🏅 Lv 0', 'inlinekeyboard;inProgress'),
            Buttons::getButton('⚔️ 0 XP', 'inlinekeyboard;inProgress'),
            Buttons::getButton('💰 0 $',  'menu;wallet'),
            Buttons::getButton('👤', 'menu;profile'),
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
//            Buttons::getButton('🕓 ' . TG_ADD_REMINDER,  'setreminder;command;start;' . $type . ';' . $id),
            Buttons::getButton('🖋 ' . TG_EDIT, $type . ';command;edit;' . $id),
            Buttons::getButton('🗑 ' . TG_DELETE, 'inlinekeyboard;delete;' . $type . ';' . $id)
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
}
