<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Modules\TgAdmin\Commands\UserCommands;

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Modules\Economy\Models\Wallets;
use Modules\TgAdmin\Renderer\Buttons;
use Modules\TgAdmin\Featuring\Messages;
use Modules\TgAdmin\Models\User\Users;
use Modules\TgAdmin\Services\AccessRights;
use Modules\Users\Models\Message\UserMessages;
use Modules\Users\Models\Person\AppUsers;
use mysql_xdevapi\Exception;
use Phalcon\Di\Di;
use Phalcon\Translate\Adapter\NativeArray;
use TelegramModels\Featuring\TelegramEbSettings;
use TelegramModels\Finance\TelegramFinMoney;
use TelegramModels\TelegramEbCompany;
use TelegramModels\TelegramEbFavourite;
use \Modules\TgAdmin\Renderer\Translator as Tr;


/**
 * Menu command
 */
class MenuCommand extends UserCommand
{

    protected $name = 'menu';
    protected $description = 'Menu command';
    protected $usage = '/menu';
    protected $version = '1.0.0';


    public function execute(): ServerResponse
    {

        $message = $this->getMessage();
        $callback = $this->getCallbackQuery();

        if (!empty($message)){
            $chat_id = $message->getChat()->getId();
            $user_id = $message->getFrom()->getId();
            $user_name = $message->getFrom()->getUsername();
            $message_id = $message->getMessageId();
        }
        if(!empty($callback)) {
            $callback_id = $callback->getId();
            $user_id = $callback->getFrom()->getId();
            $user_name = $callback->getFrom()->getUsername();
            if ($callback->getMessage()) {
                $message_id = $callback->getMessage()->getMessageId();
            }
            $data = $callback->getData();
            $data_array = explode(';', $data);
            $page = $data_array[1];
        }
        try{

            $userService = di('userService');
            $walletService = di('walletService');

            $progress_data = $userService->getUserProgress($user_id);
            $messages = $userService->getUserMessages($user_id);
            $wallet = $walletService->getBalance($user_id, "coin");

            $menu_data = array(
                "level" => $progress_data->level,
                "xp" => $progress_data->xp,
                "msg" => count($messages),
                "coin" => $wallet->balance
            );
        }catch (\Exception $e){
            Messages::sendMeMessage($e->getMessage());
        }
        $username = "yuramurahovsky";
//        $data = $this->getUpdate()->getCallbackQuery()->getData();
        //$text = $data ? $data : "false";

        if ((!empty($message) && $message->getText() == '/menu') || (!empty($callback) && $page == 'main'))  {
            $text = '<b>' . TG_MENU . ':</b>' . PHP_EOL;

            $menu_keyboard_buttons = array(
                Buttons::getMainMenu($menu_data),

                array(
                    Buttons::getButton('🎮 ' . Tr::t("TG_GAMES"),'menu;games'),
                    Buttons::getButton('🗄️ ' . Tr::t("TG_CRM"),'menu;CRM'),
                ),
                array(
                    Buttons::getButton('👤 ' . Tr::t("TG_PROFILE"),'menu;profile'),
                    Buttons::getButton('🌀 ' . Tr::t("TG_ANOTHER"),'menu;another'),
                    //                    Buttons::getButton('👥 ' . TG_USERS,'ul', 'switch_inline_query_current_chat'),
                )
            );

            if(empty($callback)) {
                return Request::sendMessage([
                    'chat_id' => $user_id,
                    'text' => $text,
                    'reply_markup' => new InlineKeyboard(...$menu_keyboard_buttons),
                    'parse_mode' => 'HTML',
                ]);
            }
        }
        elseif (!empty($callback)) {
            // access rules
            $status = AccessRights::getStatus($user_id);
            $access = AccessRights::check($user_id, 'menu_'.$page);

            if(!$access['is_allow']){
                return Request::answerCallbackQuery(
                    array(
                        'callback_query_id' => $callback->getId(),
                        'text' => $access['message'],
                        'show_alert' => 'true',
                    )
                );
            }
//            $total_money = TelegramFinMoney::findFirst("user_id = '" . $user_id . "'");
            $total_money = 50;

            switch ($page) {
                case 'CRM':{
                    $text = '<b>' . TG_MENU . ' / ' . TG_CRM . ':</b>';

                    $menu_keyboard_buttons = array(
                        Buttons::getNavigation($menu_data),
                        array(
                            Buttons::getButton('🏠 ' . Tr::t("TG_OBJECTS"), 'menu;myObjects;' ),
                            Buttons::getButton('🔍 ' . Tr::t("TG_SEARCH"),'menu;search'),
                            Buttons::getButton('📨 ' . Tr::t("TG_REQUESTS"), 'menu;myRequests;' ),
                        ),
                        array(
                            Buttons::getButton('📅 ' . Tr::t("TG_CALENDAR"),'inlinekeyboard;infoWindow;inProgress'),
                            Buttons::getButton('👥 ' . Tr::t("TG_CONTACTS"), 'inlinekeyboard;infoWindow;inProgress' ),
                            Buttons::getButton('📂 ' . Tr::t("TG_DOCUMENTS"), 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                        array(
                            Buttons::getButton('🏢 ' . Tr::t("TG_AGENCY"), 'inlinekeyboard;infoWindow;inProgress' ),
                            Buttons::getButton('⚙️ ' . Tr::t("TG_SETTINGS"), 'inlinekeyboard;infoWindow;inProgress'),
                            Buttons::getButton('📊 ' . Tr::t("TG_ANALYTICS"), 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                    );

                    break;
                } // main menu
                case 'myObjects':
                {
                    $text = '<b>' . TG_MENU. ' / ' . TG_CRM  . ' / ' . TG_MY_OBJECTS . ':</b>';

                    $menu_keyboard_buttons = array(
                        Buttons::getNavigation($menu_data, Buttons::getButton( TG_CRM, 'menu;CRM;')),
                        array(
                            Buttons::getButton('➕ ' . Tr::t("TG_NEW_OBJECT"), 'new_object;command;start'),
                            Buttons::getButton('📋 ' . Tr::t("TG_SHOW_OBJECTS"), 'mol' , 's'),
                        ),
                        array(
                            Buttons::getButton('📑 ' . Tr::t("TG_LISTS"), 'menu;lists;'),
                            Buttons::getButton('📦 ' . Tr::t("TG_ARCHIVE"), 'menu;archive' ),
                            Buttons::getButton('📣 ' . Tr::t("TG_ADVERTS"), 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                        array(
//                            Buttons::getButton('⚙ ' . TG_SETTINGS, 'menu;settings'),
                            Buttons::getButton('📤 ' . Tr::t("TG_SEND_OBJECT"), 'mol', 's'),
                            Buttons::getButton('🗂️️ ' . Tr::t("TG_SHOW_LISTS"), 'mfl' ,'sc'),
                        ),
                    );

                    break;
                }
                case 'myRequests':
                {
                    $text = '<b>' . TG_MENU . ' / ' . TG_CRM . ' / ' . TG_MY_REQUESTS . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getNavigation($menu_data, Buttons::getButton( TG_CRM, 'menu;CRM;')),
                        array(
                            Buttons::getButton('➕ ' . TG_CREATE_REQUEST, 'request;command;start'),
                            Buttons::getButton( '🗓 ' . TG_REQUESTS_LISTS, 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                        array(
                            Buttons::getButton( TG_SHOW_REQUESTS, 'mrl', 'switch_inline_query_current_chat'),
                        ),
                        array(
                            Buttons::getButton('📦 ' . Tr::t("TG_ARCHIVE"), 'menu;archive' ),
                            Buttons::getButton( TG_SHOW_REQUESTS . ' ⤴', 'mrl', 'switch_inline_query'),
                        ),
                    );
                    break;
                }
                case 'archive':{
                    $text = '<b>' . TG_MENU . ' / ' . TG_CRM . ' / ' . TG_ARCHIVE . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton( TG_CRM, 'menu;CRM;')
                        ),
                        array(
                            Buttons::getButton(TG_OBJECTS, 'maol', 'switch_inline_query_current_chat'),
                            Buttons::getButton( TG_REQUESTS, 'marl', 'switch_inline_query_current_chat'),
                        ),
                    );
                    break;
                }
                case 'showing':{
                    $text = '<b>' . TG_MENU . ' / ' . TG_CRM . ' / ' . TG_SHOWINGS . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton( TG_CRM, 'menu;CRM;')
                        ),
                        array(
                            Buttons::getButton('🆕 ' . TG_NEW_SHOWING, 'showing;command;start'),
                            Buttons::getButton( TG_SHOWINGS, 'mshl', 'switch_inline_query_current_chat'),
                        ),
                    );
                    break;
                }
                case 'tasks':{
                    $text = '<b>' . Tr::t("TG_MENU") . ' / ' . Tr::t("TG_CRM") . ' / ' . Tr::t("TG_TASKS") . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton( TG_CRM, 'menu;CRM;')
                        ),
                        array(
                            Buttons::getButton('🆕 ' . TG_NEW_TASK, 'request;command;start'),
//                            Buttons::getButton( TG_TASKS, 'marl', 'switch_inline_query_current_chat'),
                            Buttons::getButton( TG_TASKS, 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                    );
                    break;
                }
                case 'lists':{
                    $text = '<b>' . Tr::t("TG_MENU") . ' / ' . Tr::t("TG_CRM") . ' / ' . Tr::t("TG_LISTS") . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton( TG_CRM, 'menu;CRM;')
                        ),
                        array(
                            Buttons::getButton('🆕 ' . TG_NEW_LIST, 'favourite;command;start'),
//                            Buttons::getButton( TG_TASKS, 'marl', 'switch_inline_query_current_chat'),
                            Buttons::getButton(TG_SHOW_LISTS, 'mfl', 'switch_inline_query_current_chat'),
                        ),
                    );
                    break;
                }
                case 'settings':{
                    $text = '<b>' . TG_MENU . ' / ' . TG_MY_OBJECTS . ' / ' . TG_SETTINGS . ':</b>';

                    // зберігаю нові дані в БД, якщо є
                    if(isset($data_array[2]) && isset($data_array[3]) && isset($data_array[4])) {
                        switch($data_array[2]) {
                            case "select_default_list":
                            {
                                $do_check = (bool)$data_array[4];
                                $item_id = (int)$data_array[3];
                                // перевіряє чи єдиний список було вибрано
                                $selected_favourites = TelegramEbFavourite::find("user_id = '" . $user_id . "' AND status = 'active_def'");
                                if(count($selected_favourites) == 1 && !$do_check) {
                                    Messages::sendCallbackAnswer($callback_id, "Хоча б один список має бути обрано.");
                                    return Request::emptyResponse();
                                }

                                // очищає весь список лістів перед тим, як вибрати дефолтний.
                                if($selected_favourites) {
                                    foreach ($selected_favourites as $selected_favourite) {
                                        $selected_favourite->status = 'active';
                                        $selected_favourite->save();
                                    }
                                }

                                // дає списку статус default/active, якщо не існує - створює новий
                                $favourite_list = TelegramEbFavourite::findFirst("id = '" . $item_id . "'");
                                if(!$favourite_list){
                                    $favourite_list = new TelegramEbFavourite();
                                    $favourite_list->user_id = $user_id;
                                    $favourite_list->list_name = TG_NEW_LIST;
                                    $favourite_list->is_private = 0;
                                    $favourite_list->published_at = date('Y-m-d H:i:s');
                                    $favourite_list->items_type = 'advert';
                                }
                                $favourite_list->status = $do_check ? 'active_def' : 'active';
                                $favourite_list->save();
                                break;
                            }
                            case "show_my_advert":
                            {
                                $setting_option = TelegramEbSettings::findFirst([
                                    "conditions" => "user_id = :u_id: AND type = :type: AND item_option = :io:",
                                    "bind" => ["u_id" => $user_id,
                                        "type" => $data_array[2],
                                        "io" => $data_array[3],]
                                ]);

                                if (!$setting_option) {
                                    $setting_option = new TelegramEbSettings();
                                    $setting_option->user_id = $user_id;
                                    $setting_option->type = $data_array[2];
                                    $setting_option->item_option = $data_array[3];
                                }
                                $setting_option->item_value = $data_array[4];
                                $setting_option->save();
                                break;
                            }
                            default:
                            {
                                return Request::emptyResponse();
                            }
                        }
                    }

                    // ініціалізація кнопок меню
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton( TG_MY_OBJECTS, 'menu;myObjects')
                        ),
                        Buttons::getButton('⚙ Налаштування показу об\'єктів', 'inlinekeyboard;infoWindow;sma_settings;'),
                    );


                    // ініціалізація з БД прапорів для show_my_advert
                    $flags = array(
                        "sent" => true,
                        "not_sent" => true,
                        "not_actual" => true,
                    );

                    $sma_settings = TelegramEbSettings::find("type = 'show_my_advert' AND user_id = '" . $user_id . "'");

                    foreach ($flags as $f_key => $flag){
                        foreach ($sma_settings as $sma_setting){
                            if($sma_setting->item_option == $f_key){
                                $flags[$f_key] = (bool)$sma_setting->item_value;
                            }
                        }
                    }

                    // ініціалізація кнопок меню
                    foreach ($flags as $f_key => $flag){
                        $command = 'show_my_advert;' . $f_key . ';' . ($flag ? '0' : '1');
                        $menu_keyboard_buttons[] = array(
                            Buttons::getButton(($flag ? '🟩 ' : '🔲 ') . constant('TG_'.$f_key.'S'), 'menu;settings;'   . $command),
                        );
                    }

                    $menu_keyboard_buttons[] = array(
                        Buttons::getButton('⚙ Вибрати список за замовчуванням', 'inlinekeyboard;infoWindow;sdl_settings;'),
                    );

                    // ініціалізація списків об'єктів, для яких потрібно встановити дефолтний стан
                    $favourite_lists =  TelegramEbFavourite::find("user_id = '" . $user_id . "' AND status <> 'not_active'");

                    if(count($favourite_lists)) {
                        foreach ($favourite_lists as $favourite_list) {
                            $fl_status = $favourite_list->status == "active_def" ? 1 : 0;
                            $command = 'select_default_list;' . $favourite_list->id . ';' . !$fl_status;
                            $menu_keyboard_buttons[] = array(
                                Buttons::getButton(($fl_status ? '🟩 ' : '🔲 ') . $favourite_list->list_name, 'menu;settings;' . $command),
                            );
                        }
                    }else{

                        $command = 'select_default_list;;1';
                        $menu_keyboard_buttons[] = array(
                            Buttons::getButton('🔲 ' . TG_NEW_LIST, 'menu;settings;' . $command),
                        );
                    }
                    break;
                }

                case 'search':
                {
                    $text = '<b>' . TG_MENU . ' / ' . TG_SEARCH . ':</b>';

                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(),
                        array(
                            Buttons::getButton('🏠 ' . TG_ADVERTS, 'menu;advertsAll;'),
//                            Buttons::getButton('📰 ' . TG_ADVERTS, 'menu;advertsAll;'),
                        ),
                        array(
//                            Buttons::getButton('📰 ' . TG_OBJECTS, 'menu;objectsAll;'),
                            Buttons::getButton('📰 ' . TG_OBJECTS, 'inlinekeyboard;infoWindow;inProgress'),
                            Buttons::getButton('📩 ' . TG_REQUESTS, 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                    );
                    break;
                } // main menu
                case 'objectsAll':
                {
                    $text = '<b>' . TG_MENU .' / ' . TG_FIND . ' / ' . TG_OBJECTS . ':</b>';

                    $buttons_data = $this->getObjectsFilterButtonsData($data, 'objects');
                    $menu_keyboard_buttons = array();
                    foreach ($buttons_data as $key => $button_data)
                    {
                        if (!($key % 2)) {
                            $keyboard_line = array(
                                new InlineKeyboardButton($button_data),
                            );
                            if(count($buttons_data) == ($key + 1)){
                                $menu_keyboard_buttons[] = $keyboard_line;
                            }
                        }else{
                            $keyboard_line[] = new InlineKeyboardButton($button_data);
                            $menu_keyboard_buttons[] = $keyboard_line;
                        }
                    }
                    break;
                }
                case 'requestsAll':
                {
                    $text = '<b>' . TG_MENU .' -> ' . TG_REQUESTS . ' -> ' . TG_ALL_REQUESTS . ':</b>';

                    $buttons_data = $this->getCommand($data);

                    $menu_keyboard_buttons = array();
                    foreach ($buttons_data as $key => $button_data)
                    {
                        if (!($key % 2)) {
                            $keyboard_line = array(
                                new InlineKeyboardButton($button_data),
                            );
                            if(count($buttons_data) == ($key + 1)){
                                $menu_keyboard_buttons[] = $keyboard_line;
                            }
                        }else {
                            $keyboard_line[] = new InlineKeyboardButton($button_data);
                            $menu_keyboard_buttons[] = $keyboard_line;
                        }
                    }
                    break;
                }
                case 'advertsAll':
                {
                    $text = '<b>' . TG_MENU .' / ' . TG_FIND . ' / ' . TG_ADVERTS . ':</b>';

                    $buttons_data = $this->getObjectsFilterButtonsData($data, 'adverts');

                    $text .= $this->getObjectsFilterDescription($data);

                    $menu_keyboard_buttons = array();
                    foreach ($buttons_data as $key => $button_data)
                    {
                        if (!($key % 2)) {
                            $keyboard_line = array(
                                new InlineKeyboardButton($button_data),
                            );
                            if(count($buttons_data) == ($key + 1)){
                                $menu_keyboard_buttons[] = $keyboard_line;
                            }
                        }else{
                            $keyboard_line[] = new InlineKeyboardButton($button_data);
                            $menu_keyboard_buttons[] = $keyboard_line;
                        }
                    }
                    break;
                }

                case "games":{
                    $text = '<b>' . Tr::t("TG_MENU") . ' / ' . Tr::t("TG_SEARCH") . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getNavigation($menu_data),
                        array(
//                            Buttons::getButton('📰 ' . TG_OBJECTS, 'menu;objectsAll;'),
                            Buttons::getButton('📰 Paint IO', 'inlinekeyboard;infoWindow;inProgress'),
                            Buttons::getButton('📩 ' . Tr::t("TG_QUESTS"), 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                    );
                    break;
                }

                case "profile":{
                    $text = '<b>' . Tr::t("TG_MENU") . ' / ' . Tr::t("TG_PROFILE") . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getNavigation($menu_data),
                    );
                    switch ($status) {
                        case "public":{
                            $menu_keyboard_buttons[] = array(
                                Buttons::getButton(TG_CREATE, 'user;command;start'),
                                Buttons::getButton(TG_LANGUAGE, 'menu;language'),
                            );
                            break;
                        }
                        case "registered":{

                            $menu_keyboard_buttons[] = array(
                                Buttons::getButton('👤 ' . TG_EDIT, 'user;command;edit;' . $user_id),
                                Buttons::getButton("Я ріелтор", 'inlinekeyboard;infoWindow;inProgress'),
//                                Buttons::getButton("Я ріелтор", 'realtor;start'),
                            );
                            break;
                        }
                        case "realtor":{
                            $menu_keyboard_buttons[] = array(
                                Buttons::getButton('👤 ' . TG_EDIT, 'user;command;edit;' . $user_id),
                                Buttons::getButton("Я ріелтор", 'inlinekeyboard;infoWindow;inProgress'),
//                                Buttons::getButton("Я ріелтор", 'realtor;edit'),
                            );
                            $menu_keyboard_buttons[] = array(
                                Buttons::getButton('👤 ' . TG_COMPANY, 'inlinekeyboard;infoWindow;inProgress'),
                            );
                        }

                        case "paid":
                        case "admin":
                        {
                            $menu_keyboard_buttons[] = array(
                                Buttons::getButton('👤 ' . TG_EDIT, 'user;command;edit;' . $user_id),
                                Buttons::getButton("Я ріелтор", 'inlinekeyboard;infoWindow;inProgress'),
                            );
                            $menu_keyboard_buttons[] = array(
                                Buttons::getButton('👤 Корзина' , 'inlinekeyboard;infoWindow;inProgress'),
//                                Buttons::getButton('👤 Корзина' , 'inlinekeyboard;infoWindow;inProgress'),
                            );

                            break;
                        }
                    }
//                    $employee = TelegramEbEmployee("user_id = '" . $user_id . "'" );
//                    if($employee->status == "realtor" ){
//                        $menu_keyboard_buttons[] = array(
//                            Buttons::getButton('👤 ' . TG_COMPANY, 'inlinekeyboard;infoWindow;inProgress'),
//                        );
//                    }
                    break;
                } // main
                case "language":{
                    $text = '<b>' . TG_MENU . ' / ' . TG_PROFILE . ' / ' . TG_LANGUAGE . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton(TG_PROFILE,'menu;profile'), false
                        ),
                        array(
                            Buttons::getButton('🟩 Українська', 'inlinekeyboard;infoWindow;inProgress'),
                            Buttons::getButton('🔲 Polski', 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                        array(
                            Buttons::getButton('🔲 English', 'inlinekeyboard;infoWindow;inProgress'),
                            Buttons::getButton('🔲 Русский', 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                    );
                    break;
                }
                case "wallet":{
                    $total_money = TelegramFinMoney::findFirst("user_id = '" . $user_id . "'");
                    if(!$total_money){
                        $total_money = new TelegramFinMoney();
                        $total_money->set_data([
                            "user_id" => $user_id,
                            "count" => 0,
                            "status" => "active",
                        ]);
                        $total_money->save();
                    }

                    $text = '<b>' . TG_MENU . ' / ' . TG_PROFILE . ' / ' . TG_WALLET . ':</b>';
                    $text .= PHP_EOL . PHP_EOL .'<b>'. 'REcoins' . ':</b> ' . $total_money->count . '💰';
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton( TG_PROFILE,'menu;profile'),
                        ),
                        array(
                            Buttons::getButton('💳 ' . TG_TOP_UP, 'menu;top_up'),
                            Buttons::getButton('⛏ ' . TG_MINING, 'menu;mining'),
                        ),
                        array(
                            Buttons::getButton('💸 ' . TG_PAY, 'menu;pay'),
                            Buttons::getButton('🪪Переказати', 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                        array(
                            Buttons::getButton('🧰 Всі платежі', 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                    );
                    break;
                }
                case "top_up":{
                    $total_money = TelegramFinMoney::findFirst("user_id = '" . $user_id . "'");

                    $text = '<b>' . TG_MENU . ' / ' . TG_PROFILE . ' / ' . TG_WALLET . ' / ' . TG_TOP_UP . ':</b>';
                    $text .= PHP_EOL . PHP_EOL .'<b>'. 'REcoins' . ':</b> ' . $total_money->count . '💰';


                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton( TG_WALLET,'menu;wallet')
                        ),
                        array(
                            Buttons::getButton('💳 50грн', 'inlinekeyboard;get_pay_page;50'),
                            Buttons::getButton('💳 100грн', 'inlinekeyboard;get_pay_page;100'),
                        ),
                        array(
                            Buttons::getButton('💳 200грн', 'inlinekeyboard;get_pay_page;200'),
                            Buttons::getButton('💳 500грн ', 'inlinekeyboard;get_pay_page;500'),
                        ),
                        array(
                            Buttons::getButton('💳 1000грн 💳', 'inlinekeyboard;get_pay_page;1000'),
                        ),
                    );
                    break;
                }
                case "mining":{

                    $text = '<b>' . TG_MENU . ' / ' . TG_PROFILE . ' / ' . TG_WALLET . ' / ' . TG_MINING . ':</b>';
                    $text .= PHP_EOL . PHP_EOL .'<b>'. 'REcoins' . ':</b> ' . $total_money->count . '💰';


                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton( TG_WALLET,'menu;wallet')
                        ),
                        array(
                            Buttons::getButton('⛏ Перевірити чи дублікат (+1💰)', 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                        array(
                            Buttons::getButton('⛏ Перевірити на актуальність (+2💰)', 'inlinekeyboard;infoWindow;inProgress'),
                        ),
                        array(
                            Buttons::getButton('⛏ Запросити друга (+5💰)', 'inlinekeyboard;mine;add_users'),
                        ),
                        array(
                            Buttons::getButton('⛏ Пройти опитування (+100💰)', 'inlinekeyboard;infoWindow;non_poll'),
                        ),
                    );
                    break;
                }
                case "pay":{

                    $text = '<b>' . TG_MENU . ' / ' . TG_PROFILE . ' / ' . TG_WALLET . ' / ' . TG_PAY . ':</b>';
                    $text .= PHP_EOL . PHP_EOL .'<b>'. 'REcoins' . ':</b> ' . $total_money->count . '💰';

                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton( TG_WALLET,'menu;wallet'),
                        ),
                        array(
                            Buttons::getButton('💸 Місячний доступ (100💰)', 'inlinekeyboard;buy;month_access'),
                        ),
//                        array(
//                            Buttons::getButton('💸 Перевірити на актуальність (2💰)', 'inlinekeyboard;infoWindow;inProgress'),
//                        ),

                    );
                    break;
                }


                case 'another':{
                    $text = '<b>' . Tr::t("TG_MENU") . ' / ' . Tr::t("TG_ANOTHER") . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getNavigation($menu_data),
                    );
                    $menu_keyboard_buttons[] = array(
                        Buttons::getButton('Про проект', 'inlinekeyboard;infoWindow;inProgress'),
                        Buttons::getButton( Tr::t("TG_TELEGRAM_GROUPS"),'inlinekeyboard;send;telegramGroups'),
                    );

                    $menu_keyboard_buttons[] = array(
                        Buttons::getButton(Tr::t("TG_WEATHER"), 'weather'),
                        Buttons::getButton('👥 ' . Tr::t("TG_USERS"),'ul', 'sc'),
                    );
                    break;
                }// main menu


                case "company":{

                    $text = '<b>' . TG_MENU . ' / ' . TG_COMPANY . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(
                            Buttons::getButton('➡ ' . TG_PROFILE, 'menu;profile')
                        )
                    );

                    $company = TelegramEbCompany::findFirst("admin_id = '" . $user_id . "'");
                    if (!$company) {
                        $menu_keyboard_buttons[] = array(
                            Buttons::getButton(TG_CREATE, 'company;command;start'),
                            Buttons::getButton(TG_EMPLOYEES, 'inlinekeyboard;infoWindow;inProgress'),
                        );
                    }else {
                        $menu_keyboard_buttons[] = array(
                            Buttons::getButton(TG_EDIT, 'company;command;edit;' . $company->id),
//                            Buttons::getButton(TG_DOCUMENTATION, 'inlinekeyboard;infoWindow;inProgress'),
                        );
                    };
                    $menu_keyboard_buttons[] = array(
                        Buttons::getButton(TG_KNOWLEDGE_BASE, 'inlinekeyboard;infoWindow;inProgress'),
                        Buttons::getButton(TG_DOCUMENTATION, 'inlinekeyboard;infoWindow;inProgress'),
                    );
                    break;
                } // main menu

                case 'message':
                {
                    $text = '<b>' . TG_MENU . ' / ' . TG_MESSAGE . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(),
                        array(
                            new InlineKeyboardButton(array(
                                'text' => TG_OBJECTS,
                                'switch_inline_query_current_chat' => 'maol'
                            )),
                            new InlineKeyboardButton(array(
                                'text' => TG_REQUESTS,
                                'switch_inline_query_current_chat' => 'marl'
//                                'callback_data' => 'inlinekeyboard;infoWindow;inProgress'
                            )),
                        ),
                    );
                    break;
                }
                case 'catalog':{
                    $text = '<b>' . TG_MENU . ' / ' . TG_CATALOGUES . ':</b>';
                    $menu_keyboard_buttons = array(
                        Buttons::getMainMenu(),
                        array(
                            Buttons::getButton(TG_USERS, 'ul', 'switch_inline_query_current_chat'),
                            Buttons::getButton(TG_COMPANIES, 'cl', 'switch_inline_query_current_chat')
                        )
                    );
                    break;
                }
//                case 'requests':
//                {
//                    $text = '<b>' . TG_MENU . ' -> ' . TG_REQUESTS . ':</b>';
//                    $menu_keyboard_buttons = array(
//                        array(
//                            $main_menu_button,
//                        ),
//                        array(
//                            new InlineKeyboardButton(array(
//                                'text' =>'🆕 ' . TG_CREATE_REQUEST,
//                                'callback_data' => 'request;command;start',
//                            )),
//                        ),
//                        array(
//                            new InlineKeyboardButton(array(
//                                'text' => TG_MY_REQUESTS,
//                                'switch_inline_query_current_chat' => 'mrl',
//                            )),
//                            new InlineKeyboardButton(array(
//                                'text' => TG_MY_REQUESTS . ' ⤴',
//                                'switch_inline_query' => 'mrl',
//                            )),
//                        ),
//                        array(
//                            new InlineKeyboardButton(array(
//                                'text' => TG_ALL_REQUESTS,
//                                'switch_inline_query_current_chat' => 'arl',
////                                'callback_data' => 'menu;requestsAll;',
//                            )),
//                            new InlineKeyboardButton(array(
//                                'text' => TG_ARCHIVE,
//                                'switch_inline_query_current_chat' => 'marl'
////                                'callback_data' => 'inlinekeyboard;infoWindow;inProgress'
//                            )),
//                        )
//                    );
//                    break;
//                }


                default :
                    break;
            }
//            $text .= '                                           <b>🔔(5)</b>';
        }

        return Request::editMessageText([
            'message_id' => $message_id,
            'chat_id' => $user_id,
            'text' => $text,
            'reply_markup' => new InlineKeyboard(...$menu_keyboard_buttons),
            'parse_mode' => 'HTML',
        ]);
    }

    private function getObjectsFilterButtonsData($data, $class): array {

        $t_data_arr = explode(';', $data);
        $current_param = $t_data_arr[2] ?? '';

        $data_arr = array(
            'category' => ($t_data_arr[3] ?? 'rent'),
            'type' => ($t_data_arr[4] ?? 'flat'),
            'rooms' => ($t_data_arr[5] ?? ''),
            'region' => ($t_data_arr[6] ?? ''),
            'square' => ($t_data_arr[7] ?? ''),
            'floor' => ($t_data_arr[8] ?? ''),
            'price' => ($t_data_arr[9] ?? '')
        );

        // filter buttons data
        $params_data = array(
            'category' => array(
                array(
                    'text' => TG_RENT,
                    'value' => 'rent'
                ),
                array(
                    'text' => TG_SALE,
                    'value' => 'sale'
                )
            ),
            'type' => array(
                array(
                    'text' => TG_FLAT,
                    'value' => 'flat'
                ),
                array(
                    'text' => TG_HOUSE,
                    'value' => 'ho'
                ),
                array(
                    'text' => TG_COMMERCE,
                    'value' => 'com'
                ),
                array(
                    'text' => TG_GROUND,
                    'value' => 'gro'
                ),
            ),
            'rooms' => array(
                array(
                    'text' => '1' . TG_ROOM_ABB,
                    'value' => '1-1'
                ),
                array(
                    'text' => '1-2' . TG_ROOM_ABB,
                    'value' => '1-2'
                ),
                array(
                    'text' => '2-3' . TG_ROOM_ABB,
                    'value' => '2-3'
                ),
                array(
                    'text' => '3+' . TG_ROOM_ABB,
                    'value' => '3'
                )
            ),
            'region' => array(
                array(
                    'text' => 'Галицький',
                    'value' => 'gal'
                ),
                array(
                    'text' => 'Шевченківський',
                    'value' => 'shev'
                ),
                array(
                    'text' => 'Франківський',
                    'value' => 'fran'
                ),
                array(
                    'text' => 'Сихівський',
                    'value' => 'sykh'
                ),
                array(
                    'text' => 'Личаківський',
                    'value' => 'lych'
                ),
                array(
                    'text' => 'Залізничний',
                    'value' => 'zal'
                )
            ),
            'square' => array(
                array(
                    'text' => 'до 35' . TG_SQUARE_ABB,
                    'value' => '0-35'
                ),
                array(
                    'text' => '35-60' . TG_SQUARE_ABB,
                    'value' => '35-60'
                ),
                array(
                    'text' => '60-85' . TG_SQUARE_ABB,
                    'value' => '60-85'
                ),
                array(
                    'text' => '85-120' . TG_SQUARE_ABB,
                    'value' => '85-120'
                ),
                array(
                    'text' => '120-300' . TG_SQUARE_ABB,
                    'value' => '120-300'
                ),
                array(
                    'text' => '300' . TG_SQUARE_ABB,
                    'value' => '300'
                ),
            ),
            'floor' => array(
                array(
                    'text' => 'не перший ' . TG_FLOOR_ABB,
                    'value' => '2'
                ),
                array(
                    'text' => 'не останній ' . TG_FLOOR_ABB,
                    'value' => '1-41'
                ),
                array(
                    'text' => 'до 4 ' . TG_FLOOR_ABB,
                    'value' => '1-4'
                ),
                array(
                    'text' => 'від 5 ' . TG_FLOOR_ABB,
                    'value' => '5'
                ),
                array(
                    'text' => 'до 9 ' . TG_FLOOR_ABB,
                    'value' => '1-9'
                ),
                array(
                    'text' => 'від 10 ' . TG_FLOOR_ABB,
                    'value' => '10'
                ),
            ),
//            'is_new' => array(
//                array(
//                    'text' => TG_FLAT,
//                    'value' => 'Квартира'
//                ),
//                array(
//                'text' => TG_HOUSE,
//                'value' => 'Будинок'
//                ),
//                array(
//                'text' => TG_COMMERCE,
//                'value' => 'Комерція'
//                ),
//                array(
//                'text' => TG_GROUND,
//                'value' => 'Діялянка'
//                ),
//            ),
        );

        if ($data_arr["category"] == 'rent'){
            $params_data["price"] = array(
                array(
                    'text' => 'до 200$',
                    'value' => '1-200'
                ),
                array(
                    'text' => '200-350$',
                    'value' => '200-350'
                ),
                array(
                    'text' => '350-500$',
                    'value' => '350-500'
                ),
                array(
                    'text' => '500-650$',
                    'value' => '500-650'
                ),
                array(
                    'text' => '650-1000$',
                    'value' => '650-1000'
                ),
                array(
                    'text' => 'від 1000$',
                    'value' => '1000'
                ),
            );
        }else{
            $params_data["price"] = array(
                array(
                    'text' => 'до 20' . TG_PRICE_ABB,
                    'value' => '1-20'
                ),
                array(
                    'text' => '20-40' . TG_PRICE_ABB,
                    'value' => '20-40'
                ),
                array(
                    'text' => '40-80' . TG_PRICE_ABB,
                    'value' => '40-80'
                ),
                array(
                    'text' => '80-120' . TG_PRICE_ABB,
                    'value' => '80-120'
                ),
                array(
                    'text' => '120-200' . TG_PRICE_ABB,
                    'value' => '120-200'
                ),
                array(
                    'text' => 'від 200' . TG_PRICE_ABB,
                    'value' => '200'
                ),
            );
        }

        // all filters page
        if (!$current_param) {
            $params_line = '';
            foreach ($data_arr as $data_item){
                $params_line .= ";" . $data_item;
            }

            return
                array(
                    array(
                        'text' => '⬅️ Головне меню',
                        'callback_data' => 'menu;main',
                    ),
                    array(
//                        'text' => '⬅️ ' . constant('TG_' . strtoupper($class)),
                        'text' => '⬅️ ' . TG_FIND,
//                        'callback_data' => 'menu;search' . $class,
                        'callback_data' => 'menu;search',
                    ),
                    array(
                        'text' => ($data_arr['category'] ? '🟩 ' : '🔲 ') . TG_CATEGORY,
                        'callback_data' => 'menu;' . $class . 'All;category' . $params_line,
                    ),
                    array(
                        'text' => ($data_arr['type'] ? '🟩 ' : '🔲 ') . TG_OBJECT_TYPE,
                        'callback_data' => 'menu;' . $class . 'All;type' . $params_line,
                    ),
                    array(
                        'text' => ($data_arr['rooms'] ? '🟩 ' : '🔲 ') . TG_COUNT_ROOMS,
                        'callback_data' => 'menu;' . $class . 'All;rooms' . $params_line,
                    ),

                    array(
                        'text' => ($data_arr['region'] ? '🟩 ' : '🔲 ') . TG_REGION,
                        'callback_data' => 'menu;' . $class . 'All;region' . $params_line,
                    ),
                    array(
                        'text' => ($data_arr['square'] ? '🟩 ' : '🔲 ') . TG_SQUARE,
                        'callback_data' => 'menu;' . $class . 'All;square' . $params_line,
                    ),
                    array(
                        'text' => ($data_arr['floor'] ? '🟩 ' : '🔲 ') . TG_FLOOR,
                        'callback_data' => 'menu;' . $class . 'All;floor' . $params_line,
                    ),
                    array(
                        'text' => ($data_arr['price'] ? '🟩 ' : '🔲 ') . TG_PRICE,
                        'callback_data' => 'menu;' . $class . 'All;price' . $params_line,
                    ),
                    array(
                        'text' => TG_SEARCH,
//                        'switch_inline_query_current_chat' => 'search_' . $params_line,
                        'switch_inline_query_current_chat' => 's' . $class . '_' . $params_line,
                    )
                );
        };

        // create command line
        $t_command = array();
        if(isset($params_data[$current_param])) {
            foreach ($params_data[$current_param] as $index => $param_values) {
                $t_command[$index]['text'] = $param_values['text'];
                $t_command[$index]['callback_data'] = 'menu;' . $class . 'All;';

                foreach ($data_arr as $key => $data_param) {
//
                    if ($key == $current_param) {
                        $t_command[$index]['callback_data'] .= ';'. $param_values['value'];
                    } else {
                        $t_command[$index]['callback_data'] .= ';' . $data_param;
                    }
                }
            };

            $cancel_button = array(
                'text' => '🚫 ' . TG_NOT_USE,
                'callback_data' => 'menu;' . $class . 'All;'
            );
            foreach ($data_arr as $key => $data_param) {
                $cancel_button['callback_data'] .= ';' . ($key == $current_param ? '' : $data_param);
            }
            $t_command[] = $cancel_button;

        }else{
            Messages::sendCallbackAnswer($this->getCallbackQuery()->getId(),
                'Не можу згенерувати команду, параметр не корректний',
                true
            );
        }

        return $t_command;
    }

    private function getObjectsFilterDescription($data): string
    {
        $t_data_arr = explode(';', $data);
        $current_param = $t_data_arr[2] ?? '';

        $filters_data_arr = array(
            'category' => ($t_data_arr[3] ?? 'rent'),
            'type' => ($t_data_arr[4] ?? 'flat'),
            'rooms' => ($t_data_arr[5] ?? ''),
            'region' => ($t_data_arr[6] ?? ''),
            'square' => ($t_data_arr[7] ?? ''),
            'floor' => ($t_data_arr[8] ?? ''),
            'price' => ($t_data_arr[9] ?? '')
        );


        $params_data = array(
            'category' => array(
                'rent' => TG_RENT,
                'sale' => TG_SALE,
            ),
            'type' => array(
                'flat' => TG_FLAT,
                'ho' => TG_HOUSE,
                'com' => TG_COMMERCE,
                'gro' => TG_GROUND,
            ),
            'rooms' => array(
                '1-1' => '1' . TG_ROOM_ABB,
                '1-2' => '1-2' . TG_ROOM_ABB,
                '2-3' => '2-3' . TG_ROOM_ABB,
                '3' => '3+' . TG_ROOM_ABB,
            ),
            'region' => array(
                'gal' => 'Галицький',
                'shev' => 'Шевченківський',
                'fran' => 'Франківський',
                'sykh' => 'Сихівський',
                'lych' => 'Личаківський',
                'zal' => 'Залізничний',
            ),
            'square' => array(
                '0-35' => 'до 35' . TG_SQUARE_ABB,
                '35-60' => '35-60' . TG_SQUARE_ABB,
                '60-85' => '60-85' . TG_SQUARE_ABB,
                '85-120' => '85-120' . TG_SQUARE_ABB,
                '120-300' => '120-300' . TG_SQUARE_ABB,
                '300' => '300' . TG_SQUARE_ABB,
            ),
            'floor' => array(
                '2' => 'не перший ' . TG_FLOOR_ABB,
                '1-41' => 'не останній ' . TG_FLOOR_ABB,
                '1-4' => 'до 4 ' . TG_FLOOR_ABB,
                '5' => 'від 5 ' . TG_FLOOR_ABB,
                '1-9' => 'до 9 ' . TG_FLOOR_ABB,
                '10' => 'від 10 ' . TG_FLOOR_ABB,
            ),
//            'is_new' => array(
//                array(
//                    'text' => TG_FLAT,
//                    'value' => 'Квартира'
//                ),
//                array(
//                'text' => TG_HOUSE,
//                'value' => 'Будинок'
//                ),
//                array(
//                'text' => TG_COMMERCE,
//                'value' => 'Комерція'
//                ),
//                array(
//                'text' => TG_GROUND,
//                'value' => 'Діялянка'
//                ),
//            ),
        );

        if ($filters_data_arr["category"] == 'rent') {
            $params_data["price"] = array(
                '1-200' => 'до 200$',
                '200-350' => '200-350$',
                '350-500' => '350-500$',
                '500-650' => '500-650$',
                '650-1000' => '650-1000$',
                '1000' => 'від 1000$',
            );
        } else {
            $params_data["price"] = array(
                '1-20' => 'до 20' . TG_PRICE_ABB,
                '20-40' => '20-40' . TG_PRICE_ABB,
                '40-80' => '40-80' . TG_PRICE_ABB,
                '80-120' => '80-120' . TG_PRICE_ABB,
                '120-200' => '120-200' . TG_PRICE_ABB,
                '200' => 'від 200' . TG_PRICE_ABB,
            );
        }

        $data_description = PHP_EOL . PHP_EOL . '';
        foreach ($filters_data_arr as $key => $data) {
            if (!$filters_data_arr[$key]) { continue; }
            $data_description .= sprintf("%-30s", '<b>' . constant('TG_'.$key) . '</b> - ' ) . $params_data[$key][$filters_data_arr[$key]] . PHP_EOL;
        }

        return $data_description;
    }
}