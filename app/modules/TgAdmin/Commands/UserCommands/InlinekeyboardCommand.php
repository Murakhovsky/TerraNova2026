<?php

/**
 * This file is part of the PHP Telegram Bot example-bot package.
 * https://github.com/php-telegram-bot/example-bot/
 *
 * (c) PHP Telegram Bot Team
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Modules\TgAdmin\Commands\UserCommands;

/**
 * User "/inlinekeyboard" command
 *
 * Display an inline keyboard with a few buttons.
 *
 * This command requires CallbackqueryCommand to work!
 *
 * @see Callbackquery1234Command.php
 */

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

use Modules\TgAdmin\Renderer\Buttons;
use Modules\TgAdmin\Featuring\Cards;
use Modules\TgAdmin\Renderer\Messages;



use Modules\TgAdmin\Models\Featuring\BaseModel;
use Telegram\Featuring\MerchantPaymInfo;

use TelegramModels\Adverts\RealtyTgAdvert;
use TelegramModels\Adverts\RealtyTgArchive;
use TelegramModels\Finance\TelegramFinBasket;
use TelegramModels\Finance\TelegramFinMaxProdInUserStatus;
use TelegramModels\Finance\TelegramFinMoney;
use TelegramModels\Finance\TelegramFinProduct;
use TelegramModels\Finance\TelegramFinTransaction;
use TelegramModels\TelegramBaseModel;
use TelegramModels\TelegramEbCompany;
use TelegramModels\TelegramEbFavourite;
use TelegramModels\Adverts\RealtyTg;
use TelegramModels\Featuring\TelegramEbFavouriteItems;
use TelegramModels\Featuring\TelegramEbNotActualAdverts;
use TelegramModels\TelegramEbUser;
use TelegramModels\Featuring\TelegramEbUserLikes;
use Phalcon\Mvc\Model;
use Exception as LocalTGException;
use DateTime;

class InlinekeyboardCommand extends UserCommand
{
    /**
     * @var string
     */
    protected $name = 'inlinekeyboard';

    /**
     * @var string
     */
    protected $description = 'Show inline keyboard';

    /**
     * @var string
     */
    protected $usage = '/inlinekeyboard';

    /**
     * @var string
     */
    protected $version = '0.2.0';

    /**
     * Main command execution
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {try {
        $message = $this->getMessage();
        $callback_query = $this->getCallbackQuery();
        //$chosen_inline_result = $this->getChosenInlineResult();

        if (!empty($message)) {
            $chat = $message->getChat();
            $chat_id = $message->getChat()->getId();
            $user_id = $message->getFrom()->getId();
            $user_name = $message->getFrom()->getUsername();
            $text = trim($message->getText(true));

            $data = array(
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
            );
        } elseif (!empty($callback_query)) {
            $result = Request::emptyResponse();
            $inline_message = $callback_query->getMessage();
            $callback_id = $callback_query->getId();
            $user_t = $callback_query->getFrom();
            if($inline_message){
                $message_id = $inline_message->getMessageId();
                $chat_id = $inline_message->getChat()->getId();
            }
            $user_id = $callback_query->getFrom()->getId();

            $keyboard_data = explode(";", $callback_query->getData());
            $command = isset($keyboard_data[1]) ? $keyboard_data[1] : '-';
            $command_val = isset($keyboard_data[2]) ? $keyboard_data[2] : '_';
            $param1 = isset($keyboard_data[3]) ? $keyboard_data[3] : false;
//            $text = trim($message->getText(true));

            switch ($command) {
                case 'infoWindow':{
                    $text = 'Невідома команда';
                    switch ($command_val){
                        case 'inProgress':{
                            $text = 'Функція в процесі розробки';
                            break;
                        }
                        case 'sma_settings':{ //show my adverts settings
                            $text = 'Виберіть типи оголошень, які будуть відображатись при натисканні на кнопку "' . TG_SHOW_OBJECT . '".';
                            break;
                        }
                        case 'sdl_settings':{ //select default list settings
                            $text = 'Виберіть головний список(ки) оголошень, в який(які) будуть додаватись об\'єкти з груп, при натисканні на кнопку "В обрані".';
                            break;
                        }
                        case 'non_poll':{
                            $text = 'Зараз немає активних опитувань';
                            break;
                        }
                    }
                    return Request::answerCallbackQuery(
                        array(
                            'callback_query_id' => $callback_query->getId(),
                            'text' => $text,
                            'show_alert' => 'true',
                        )
                    );
                    break;
                }
                case 'send':{
                    switch ($command_val) {
                        case "telegramGroups":
                        {
                            $tg_group_text = '<b>ПОСИЛАННЯ</b>' . PHP_EOL . PHP_EOL .
                            'Підписатись на <a href="tg://resolve?domain=EstateBookAdvertBot">Advert Bot</a>' . PHP_EOL . PHP_EOL .
                            '<a href="https://t.me/+Gnn5hNV8bqsyNDcy">Група по продажу</a>' . PHP_EOL . PHP_EOL .
                            '<a href="https://t.me/+lE3Gpm74WCpiMWEy">Група по оренді</a>' . PHP_EOL . PHP_EOL .
                            '<a href="https://t.me/+OVYmRSCl1oMyYjRi">Група для обговорення проекту</a>' .
                            ' - заходьте, ставте питання, висловлюйте свої враження та ідеї!' . PHP_EOL . PHP_EOL;

                            return Messages::sendMessage($user_id, $tg_group_text);
                        }
                        case "object":{
                            break;
                        }
                    }
                    break;
                }


                case 'inlineKBPhotos':
                {
                    $type = $command_val;
                    $object_id = $param1;
                    if ($type == 'AdvertTG') {
                        $model = 'TelegramModels\Adverts\RealtyTgAdvert';
                    } else {
                        $model = 'Parser\Advert\Realty' . $type;
                    }
                    if (!class_exists($model)){Messages::sendCallbackAnswer($callback_id, "Не можу визначити тип об'єкту.");break;}

                    $object_data = $model::getThisByID($object_id);
                    $media = array();

                    $photos = isset($object_data->photos_id) && $object_data->photos_id ? $object_data->photos_id : (isset($object_data->photos_url) ? $object_data->photos_url : $object_data->img_links);
                    $photos_arr = json_decode($photos, JSON_OBJECT_AS_ARRAY);
                    foreach ($photos_arr as $key => $photo) {
                        $media[] = new InputMediaPhoto(['media' => $photo]);
                        if (!(++$key % 10)) {
                            $result = Request::sendMediaGroup([
                                'chat_id' => $this->user_id,
                                'media' => $media,
                            ]);
                            $media = array();
                        }
                    }
                    if (count($media)) {
                        $result = Request::sendMediaGroup([
                            'chat_id' => $user_id,
                            'media' => $media,
                        ]);
                    }
//
                    if (!$result->isOk()) {
                        Messages::sendCallbackAnswer($callback_id, 'На жаль, фото не знайдено.');
//                        $result = Request::sendMessage([
//                            'chat_id' => 522625209,
//                            'text' => json_encode($result)
//                        ]);
                    }
                    break;
                }
                case 'setFavourite':
                    if($command_val == 'set' || $command_val == 'unset'){
                        $favourite = TelegramEbFavourite::findFirst(
                            "id = '" . $keyboard_data[4] . "'"
                        );

                        $is_item_in_favourite = TelegramEbFavouriteItems::findFirst(
                            "list_id = '" . $keyboard_data[4] . "' AND item_id = '" . $keyboard_data[3] . "'"
                        );


                        if($is_item_in_favourite){
                            if(!$is_item_in_favourite->delete()){
                                $mess = '';
                                foreach ($is_item_in_favourite->getMessages() as $msg) {
                                    $mess .= $msg;
                                }
                                throw new TelegramException('Не вдалося зберегти дані в базу (/setFavourite > end)' . PHP_EOL . $mess);
                            }else {
                                Request::answerCallbackQuery(
                                    array(
                                        'callback_query_id' => $callback_query->getId(),
                                        'text' => 'Об\'єкт видалено зі списку "' . $favourite->list_name . '"',
//                                        'show_alert' => 'true',
                                    )
                                );
                            };
                        }else {

                            $favourite_item = new TelegramEbFavouriteItems();
                            $favourite_item->item_id = $keyboard_data[3];
                            $favourite_item->list_id = $keyboard_data[4];
                            if (!$favourite_item->save()) {
                                $mess = '';
                                foreach ($favourite_item->getMessages() as $msg) {
                                    $mess .= $msg;
                                }
                                throw new TelegramException('Не вдалося зберегти дані в базу (/setFavourite > end)' . PHP_EOL . $mess);
                            } else {
                                Request::answerCallbackQuery(
                                    array(
                                        'callback_query_id' => $callback_query->getId(),
                                        'text' => 'Об\'єкт збережено до списку "' . $favourite->list_name . '"',
//                                        'show_alert' => 'true',
                                    )
                                );
                            }
                        }
                    }

                    //get favourite list
                    $favourite_list = TelegramEbFavourite::find(
                        "user_id = '" . $user_id . "' AND status <> 'not_active'"
                    );
                    $fl_text = 'Список обраних:';
                    if(count($favourite_list)) {
                        foreach ($favourite_list as $favourite) {

                            $favourite_items = TelegramEbFavouriteItems::findFirst(
                                "list_id = '" . $favourite->id . "' AND item_id = '" . $keyboard_data[3] . "'"
                            );
                            if($favourite_items) {
                                $inline_keyboard_lines[] = array(
                                    new InlineKeyboardButton(['text' => '🟩 ' . $favourite->list_name, 'callback_data' => 'inlinekeyboard;setFavourite;unset;' . $keyboard_data[3] . ';' . $favourite->id]),
                                );
                            }else {
                                $inline_keyboard_lines[] = array(
                                    new InlineKeyboardButton(['text' => '🔲 ' . $favourite->list_name, 'callback_data' => 'inlinekeyboard;setFavourite;set;' . $keyboard_data[3] . ';' . $favourite->id]),
                                );
                            }
                        }
                    }else{
                        $fl_text = 'У вас ще немає списків обраних:';
                    }
                    $inline_keyboard_lines[] = array(
                        new InlineKeyboardButton(array(
                            'text' => '🆕 ' . TG_CREATE_LIST,
                            'callback_data' => 'favourite;command;start',
                        )),
                        new InlineKeyboardButton(array(
                            'text' => '🔄 ' . TG_RELOAD,
                            'callback_data' => 'inlinekeyboard;setFavourite;reload;' . $keyboard_data[3],
                        )),
                    );
                    $inline_keyboard_lines[] = array(
//                        new InlineKeyboardButton(['text' => '📚 ' . TG_TO_ARCHIVE, 'callback_data' => 'inlinekeyboard;toArchive;' . $keyboard_data[3]]),
                        new InlineKeyboardButton(['text' => '📚 ' . TG_TO_ARCHIVE, 'callback_data' => 'inlinekeyboard;infoWindow;inProgress']),
                        new InlineKeyboardButton(['text' => '❌ ' . TG_CLOSE, 'callback_data' => 'inlinekeyboard;close']),
                    );
                    $inline_keyboard = new InlineKeyboard(...$inline_keyboard_lines);

                    if($command_val == 'getList') {
                        return Request::sendMessage([
                            'chat_id' => $user_id,
                            'text' => $fl_text,
                            'reply_markup' => $inline_keyboard,
                        ]);

                    }else{ // empty or 'reload'
                        if ($command_val == 'reload') {
                            Request::answerCallbackQuery(
                                array(
                                    'callback_query_id' => $callback_query->getId(),
                                    'text' => 'Списки оновлено',
//                                    'show_alert' => 'true',
                                )
                            );
                        }
                        return Request::editMessageText([
                            'chat_id' => $user_id,
                            'message_id' => $message_id,
                            'text' => $fl_text,
                            'reply_markup' => $inline_keyboard,
                        ]);
                    }

                    break;
                case 'setToList':{
                    // перевірка чи користувач зареєстрований
                    $current_user = TelegramEbUser::findFirst("id='" . $user_id . "'");
                    if (!$current_user) {
                        throw new LocalTGException('Для того, щоб зберігати оголошення в обрані та мати доступ до інших можливостей сервісу - потрібно підписатись на @EstateBookAdvertBot.');
                    }
                    $params = explode(',', $command_val);
                    $type = $params[2];
                    $item_id = $params[1] ?? 1;
                    $is_tags = $params[4] == 'tags_t' ? true : false;

                    // пошук списку за замовчуванням
                    $default_list = TelegramEbFavourite::findFirst("user_id = '" . $user_id . "' AND status = 'active_def'");
                    if (!$default_list) {
                        throw new LocalTGException('Спершу оберіть список за замовчуванням в Меню->Об\'єкти->Налаштування.');
                    }

                    // перевірка чи існує вже заданий зв'язок
                    $current_favourite_item = TelegramEbFavouriteItems::findFirst("list_id='" . $default_list->id . "' AND item_id='" . $item_id . "' AND type='" . $type . "'");
                    if ($current_favourite_item) {
                        throw new LocalTGException('Це оголошення вже є у списку "' . $default_list->list_name . '"');
                    }

                    // створення нового зв'язку
                    $current_favourite_item = new TelegramEbFavouriteItems();
                    $current_favourite_item->item_id = $item_id;
                    $current_favourite_item->list_id = $default_list->id;
                    $current_favourite_item->type = $type;
                    if(!$current_favourite_item->save()){
                        throw new LocalTGException('Не вдалось зберегти оголошення у список "' . $default_list->list_name . '"');
                    };
                    Messages::sendCallbackAnswer($callback_id,'Додано до "' . $default_list->list_name . '"');
                    break;
                }
                case 'setRecipients':

                    if ($command_val == 'OLX' || $command_val == 'RE') {
                        return Request::answerCallbackQuery(
                            array(
                                'callback_query_id' => $callback_query->getId(),
                                'text' => 'В процесі розробки',
//                                    'show_alert' => 'true',
                            )
                        );
                    }
                    $flags = array(
                        "Chat" => isset($keyboard_data[4]) ? (bool)$keyboard_data[4] : true,
                        "OLX" => isset($keyboard_data[5]) ? (bool)$keyboard_data[5] : false,
                        "RE" => isset($keyboard_data[6]) ? (bool)$keyboard_data[6] : false,
                    );
                    $send_choice_list = '';
                    foreach ($flags as $key => $flag){
                        $send_choice_list .= $flag . ';';
                        $choice_list = '';
                        foreach ($flags as $key2 => $flag2){
                            $choice_list .= ($key == $key2 ? !$flag2 : $flag2) . ';';
                        }
                        $inline_keyboard_lines[] = array(
                            new InlineKeyboardButton(['text' => ($flag ? '🟩 ' : '🔲 ') . $key, 'callback_data' => 'inlinekeyboard;setRecipients;' . $key . ';' . $keyboard_data[3] . ';' . $choice_list]),
                        );
                    }
                    $fl_text = 'Список отримувачів:';

                    $inline_keyboard_lines[] = array(
                        new InlineKeyboardButton(['text' => '➡ ' . TG_SEND, 'callback_data' => 'inlinekeyboard;sendObject;' . $keyboard_data[3] . ';' . $send_choice_list]),
                        new InlineKeyboardButton(['text' => '❌ ' . TG_CLOSE, 'callback_data' => 'inlinekeyboard;close']),
                    );
                    $inline_keyboard = new InlineKeyboard(...$inline_keyboard_lines);

                    $send_data = array(
                        'chat_id' => $user_id,
                        'text' => $fl_text,
                        'reply_markup' => $inline_keyboard,
                    );
                    if($command_val == 'getList') {
                        return Request::sendMessage($send_data);

                    }else{ // empty or 'reload'
                        $send_data['message_id'] = $message_id;
                        return Request::editMessageText($send_data);
                    }
                    break;

                case 'get_pay_page':{
                    $amount = 100 * (int)$command_val;
                    if(!$amount){Messages::sendCallbackAnswer($callback_id, 'Помилка програми');break;}
                    $url = 'https://api.monobank.ua/api/merchant/invoice/create';
                    $token = 'mlpdY6ugi2PpYC9WvLpSMgg';
                    $pay_info = new MerchantPaymInfo(
                        $command_val . "REcoins - це " . $command_val . "гр щастя",
                        $user_id
                    );
                    $payload = json_encode(array(
                        'amount' => $amount,
                        'ccy' => 980, //UAH - ISO 4217
                        'redirectUrl' => 'https://t.me/EstateBookAdvertBot',
                        'webHookUrl' => 'https://estatebook.space/monobank/hooker',
                        'merchantPaymInfo' => $pay_info,
                    ));

                    $headers = array(
                        "Content-Type: application/json",
                        "X-Token: " . $token,
//                        "Host: 'https://estatebook.space/'"
                    );
                    $defaults = array(
                        CURLOPT_CUSTOMREQUEST => "POST",
                        CURLOPT_POSTFIELDS => $payload,
                        CURLOPT_HTTPHEADER => $headers,
                        CURLOPT_RETURNTRANSFER => true
                    );
                    $ch = curl_init($url);

                    curl_setopt_array($ch, ($defaults));

                    $result = curl_exec($ch);

                    if(curl_error($ch)) {
                        Messages::sendMeMessage('Pay_page error:' . curl_error($ch));
                    }else {
                        $res_arr = json_decode($result);
                        $info_text = "<a href='" . $res_arr->pageUrl . "'> Посилання на оплату</a> <b>" . $command_val . "REcoins</b> за " . $command_val . "грн.";
                        Messages::sendMessage($user_id, $info_text);
//                        Messages::sendMeMessage($res_arr->invoiceId);
                    }
                    curl_close($ch);
                    break;
                }
                case 'buy':{
                    switch ($command_val){
                        case "month_access":{
                            $prod_code = 1001;
                            break;
                        }
                    }

                    $result = MerchantPaymInfo::create_transaction($prod_code, $user_id);
//                    if($result["is_ok"] == false){}
                    Messages::sendCallbackAnswer($callback_id, $result["message"]);

                    // message
                    // #TODO create action for message

                    break;
                }
                case 'mine':{
                    switch ($command_val){
                        case "add_users":{
                            $prod_code = 1002;
                            $result = Request::sendMessage([
                                "text" => "Додавайте нових користувачів у групи по нерухомості та отримуйте винагороду." . PHP_EOL . PHP_EOL .
                                    "<a href='https://t.me/rentlvivadverts'>Оренда нерухомості</a>" . PHP_EOL .
                                    "<a href='https://t.me/salelvivadverts'>Продаж нерухомості</a>" . PHP_EOL ,
                                "chat_id" => $user_id,
                                "parse_mode" => "HTML",
                                "reply_markup" => new InlineKeyboard(...array([
                                    Buttons::getButton('❌ ' . TG_CLOSE, 'inlinekeyboard;close'),
                                ]))
                            ]);

                            break;
                        }
                    }


                    break;
                }


                //                case 'addUserToCompany':{
//
//                    $employee_id = $command_val;
//
//                    $employee = TelegramEbUser::findFirst("id='".$employee_id."'");
//                    $company = TelegramEbCompany::findFirst("admin_id = '" . $user_id . "'");
//
//                    if($employee && $command){
//                        $employee->company_id = $company->id;
//                        $employee->save();
//
//                        $company_employees = json_decode($company->employees);
//                        array_push($company_employees, $employee_id);
//                        $company->employees = json_encode($company_employees);
//                        $company->save();
//
//                        $message = Cards::userCard($employee, $user_id);
//
//                        return Request::editMessageText([
//                            'inline_message_id' => $callback_query->getInlineMessageId(),
//                            'text' => $message["message_text"],
//                            'reply_markup' => $message["reply_markup"],
//                            'parse_mode' => 'HTML',
//                        ]);
//                    }else{
//                        // TODO exception and message. can't add user to company
//                    }
//                    break;
//                }
                case 'dismissUserFromCompany':{

                    $employee_id = $command_val;

                    $employee = TelegramEbUser::findFirst("id='".$employee_id."'");
                    $company = TelegramEbCompany::findFirst("id='".$employee->company_id."'");

                    if($employee || $command){
                        $employee->company_id = null;
                        $employee->save();

                        $company_employees = json_decode($company->employees);
                        $company->employees = json_encode(array_diff($company_employees, [$employee_id]));
                        $company->save();

                        $message = Cards::userCard($employee, $user_id);

                        return Request::editMessageText([
                            'inline_message_id' => $callback_query->getInlineMessageId(),
                            'text' => $message["message_text"],
                            'reply_markup' => $message["reply_markup"],
                            'parse_mode' => 'HTML',
                        ]);
                    }else{
                        // TODO exception and message, cant add user to company
                    }
                    break;
                }
                case 'change_personal_rating':{
                    $rating = explode(',', $command_val)[0];
                    $liked_id = explode(',', $command_val)[1];

                    $user_likes = TelegramEbUserLikes::findFirst([
                        'who_id = :who_id: AND whom_id = :whom_id:',
                        'bind' => [
                            'who_id' => $user_id,
                            'whom_id' => $liked_id,
                        ],
                    ]);

                    if($user_likes){
                        if($user_likes->rating == $rating){
                            $user_likes->rating = 0;
                        }else {
                            $user_likes->rating = $rating;
                        }
                    }else{
                        $user_likes = new TelegramEbUserLikes();
                        $user_likes->constructor([$user_id, $liked_id, $rating]);
                    }
                    $user_likes->save();

                    $user_liked = TelegramEbUser::findFirst("id='".$liked_id."'");
                    $message = Cards::userCard( $user_liked, $user_id);

                    return Request::editMessageText([
                        'inline_message_id' => $callback_query->getInlineMessageId(),
                        'text' => $message["message_text"],
                        'reply_markup' => $message["reply_markup"],
                        'parse_mode' => 'HTML',
                    ]);
                    break;
                }
                case 'toArchive':{
                    $object = RealtyTg::findFirst(
                        'id = "' . $keyboard_data[2] . '"'
                    );
                    $archive = new RealtyTgArchive();
                    if (!$archive->save($object->toArray())) {
                        $mess = '';
                        foreach ($archive->getMessages() as $msg) {
                            $mess .= $msg;
                        }
                        throw new TelegramException('Не вдалось перенести дані в архів (/toArchive)' . PHP_EOL . $mess);
                    }else{
                        $object->delete();
                        Request::answerCallbackQuery(
                            array(
                                'callback_query_id' => $callback_query->getId(),
                                'text'              => 'Об\'єкт перенесено до архіву',
                                'show_alert'        => 'true',
                            )
                        );
                        return Request::deleteMessage(
                            [
                                'chat_id'       => $user_id,
                                'message_id'    => $message_id,
                            ]
                        );
                    }

                    break;
                }
                case 'fromArchive':{
                    $archive = RealtyTgArchive::findFirst(
                        'id = "' . $keyboard_data[2] . '"'
                    );
                    $object = new RealtyTg();
                    if (!$object->save($archive->toArray())) {
                        $mess = '';
                        foreach ($object->getMessages() as $msg) {
                            $mess .= $msg;
                        }
                        throw new TelegramException('Не вдалось перенести дані з архіву (/fromArchive)' . PHP_EOL . $mess);
                    }else{
                        $archive->delete();
                        Request::answerCallbackQuery(
                            array(
                                'callback_query_id' => $callback_query->getId(),
                                'text'              => 'Об\'єкт перенесено до архіву',
                                'show_alert'        => 'true',
                            )
                        );
                        return Request::deleteMessage(
                            [
                                'chat_id'       => $user_id,
                                'message_id'    => $message_id,
                            ]
                        );
                    }

                    break;
                }

                case 'notRegistered':
                    return Request::answerCallbackQuery(
                        array(
                            'callback_query_id' => $callback_query->getId(),
                            'text' => 'Спершу потрібно заповнити профіль',
                            'show_alert' => 'true',
                        )
                    );
                    break;
                case 'delete':{
                    $model_type = '';
                    $dell_massage_text = '';
                    // TODO перенести цю дічь в Advert
                    switch ($keyboard_data[2]) {
                        case "submit":
                        case "object":
                        {
                            $dell_massage_text = TG_WANT_DELETE_OBJECT;
                            $model_type = '\TelegramModels\Adverts\RealtyTgAdvert';
                            break;
                        }
                        case 'request':{
                            $dell_massage_text = TG_WANT_DELETE_REQUEST;
                            $model_type = 'TelegramModels\TelegramEbRequest';
                            break;
                        }
                        case 'archive':{
                            $dell_massage_text = TG_WANT_DELETE_ARCHIVE_OBJECT;
                            $model_type = '\TelegramModels\Adverts\RealtyTgArchive';
                            break;
                        }
                        case 'favourite':{
                            $dell_massage_text = TG_WANT_DELETE_ARCHIVE_FAVOURITE;
                            $model_type = '\TelegramModels\TelegramEbFavourite';
                            $return_command = 'reloadFavList';
                            break;
                        }
                        case 'favouriteItem':{
                            $dell_massage_text = TG_WANT_DELETE_ARCHIVE_FAVOURITE_ITEM;
                            $model_type = '\TelegramModels\Featuring\TelegramEbFavouriteItems';
                            $return_command = 'reloadFavList';
                            break;
                        }
                        case 'user':{
                            $dell_massage_text = TG_WANT_DELETE_USER;
                            $model_type = '\TelegramModels\TelegramEbUser';
                            $return_command = 'reloadFavList';
                            break;
                        }
                        case 'company':{
                            $dell_massage_text = TG_WANT_DELETE_COMPANY;
                            $model_type = '\TelegramModels\TelegramEbCompany';
                            $return_command = 'reloadFavList';
                            break;
                        }
                        case 'showing':{
                            $dell_massage_text = TG_WANT_DELETE_SHOWING;
                            $model_type = '\TelegramModels\TelegramEbShowing';
                            $return_command = 'reloadFavList';
                            break;
                        }
                    }
                    if($keyboard_data[4] == 'confirmed'){
                        $item = $model_type::findFirst(
                            'id = "' . $keyboard_data[3] . '"'
                        );
                        $item->status = 'not_active';
//                        if($item->delete()){
                        if($item->save()) {
                            Request::answerCallbackQuery(
                                array(
                                    'callback_query_id' => $callback_query->getId(),
                                    'text' => 'Об\'єкт видалено',
//                                    'show_alert'        => 'true',
                                )
                            );
                            // TODO видаляти ще повідомлення з якого прийшов запит на видалення
                            if (isset($return_command)) {

                            };
                            return Request::deleteMessage(
                                [
                                    'chat_id' => $user_id,
                                    'message_id' => $message_id,
                                ]
                            );

                        };
                    }else {
                        // Todo перенести на загальну функцію dialogWindow
                        $inline_keyboard_lines[] = array(
                            new InlineKeyboardButton(['text' => '✅ ' . TG_YES, 'callback_data' => 'inlinekeyboard;delete;' . $keyboard_data[2] . ';' . $keyboard_data[3] . ';confirmed']),
                            new InlineKeyboardButton(['text' => '❌ ' . TG_NO, 'callback_data' => 'inlinekeyboard;close']),
                        );
                        $inline_keyboard = new InlineKeyboard(...$inline_keyboard_lines);

                        return Request::sendMessage([
                            'chat_id' => $user_id,
                            'text' => $dell_massage_text,
                            'reply_markup' => $inline_keyboard
                        ]);
                    }

                    break;
                }
                case 'answerDialog':{ // answerDialog should be before close
                    if ($command_val){
                        $keyboard_data = explode("_", $callback_query->getData());
                        $command = isset($keyboard_data[1])  ? $keyboard_data[1] : '-';
                    }
                    return Request::deleteMessage(
                        [
                            'chat_id' => $user_id,
                            'message_id' => $message_id,
                        ]
                    );

                }
                case 'close':{
                    if (isset($message_id)) {
                        return Request::deleteMessage(
                            [
                                'chat_id' => $user_id,
                                'message_id' => $message_id,
                            ]
                        );
                    }else {
                        return Request::editMessageText([
                            'inline_message_id' => $callback_query->getInlineMessageId(),
                            'text' => 'Закрито'
                        ]);
                    }
                    break;
                }

                case 'sendObject':{

                    $flags = array(
                        "Chat" => isset($keyboard_data[3]) ? (bool)$keyboard_data[3] : false,
                        "OLX" => isset($keyboard_data[4]) ? (bool)$keyboard_data[4] : false,
                        "RE" => isset($keyboard_data[5]) ? (bool)$keyboard_data[5] : false,
                    );
                    $text ='ObjectID:' . $keyboard_data[2] . ';  ';

                    foreach ($flags as $key => $flag) {
                        $text .= $key . ': ' . (int)$flag . ';  ';

                    }
                    $callback_text = "Оберіть пункти зі списку";
                    if($flags["Chat"]) {
                        $advert = RealtyTgAdvert::findFirst("id=" . $keyboard_data[2]);
                        $dateDiff = date_diff(new DateTime(), new DateTime($advert->updated_at))->days;

                        if ((int)$advert->message_id && $dateDiff < 1) {
                            Messages::sendCallbackAnswer($callback_id, "Повідомлення в чаті можна оновити не частіше ніж раз на добу.", true);
                            return Request::emptyResponse();
                        }

                        $post_data = $advert->getPost();
                        $request = Request::sendMessage($post_data);

                        if ($request->isOk()) {
                            $callback_text = 'Повіломлення надіслано.';
                            $advert->updated_at = date('Y-m-d H:i:s');
                            $advert->message_id = $request->getResult()->message_id;
                            if (!$advert->save()) {
                                $callback_text = 'Оолошення не оновилось';
                            }
                        }else{
                            $callback_text = $request->getDescription();
                        }
                    }
                    Messages::sendCallbackAnswer($callback_id, $callback_text);
                    break;
                }
                case 'collapse':{
                    $params = explode(',', $command_val);
                    $type = $params[0];
                    $data_id = $params[1];

                    $request_data = [
                        'text' => '<b>'.  Messages::getTittle($type, $data_id, $params[2]) . '</b>',
                        'reply_markup' => new InlineKeyboard(... array(array(
                            Buttons::getButton('⏬ ' . TG_DEPLOY, 'inlinekeyboard;deploy;' . $type . ',' . $data_id . ',' . $params[2])
                        ))),
                        'parse_mode' => 'HTML',
                    ];
                    if ($inline_message) {
                        $request_data['chat_id'] = $chat_id;
                        $request_data['message_id'] = $message_id;
                    } else {
                        $request_data['inline_message_id'] = $callback_query->getInlineMessageId();
                    }
                    $result = Request::editMessageText($request_data);
                    break;
                }
                case "not_actual":{
                    $current_user = TelegramEbUser::findFirst("id='" . $user_id. "'");
                    if(!$current_user){
                        Messages::sendCallbackAnswer($callback_id,'Для того, щоб мати можливість залишити відгук та мати доступ до інших можливостей сервісу - потрібно підписатись на @EstateBookAdvertBot.', true);
                        return Request::emptyResponse();
                    }
                    $params = explode(',', $command_val);
                    $is_tags = $params[4] == 'tags_t' ? true : false;
                    $currentRating = TelegramEbNotActualAdverts::findFirst("object_id='" . $params[1] ."' AND user_id='" . $user_id . "' AND type='" . $params[2] . "'");

                    if($currentRating){
                        if ($currentRating->rating == $params[3]){
                            $currentRating->delete();
                        }else{
                            $currentRating->rating = $params[3];
                            $currentRating->save();
                        }
                    }else {
                        $newRating = new TelegramEbNotActualAdverts();
                        $newRating->setDataFromConversation(array(
                            'object_id' => $params[1],
                            'type'      => $params[2],
                            'user_id'   => $user_id,
                            'rating'    => $params[3],
                        ));


                        if (!$newRating->save()) {
                            $mess = 'Не можу зберегти рейтинг: ' . PHP_EOL;
                            foreach ($newRating->getMessages() as $msg) {
                                $mess .= $msg . PHP_EOL;
                            }
                            Messages::sendCallbackAnswer($callback_query->getId(), $mess);
                            break;
                        }else{
//                            Messages::sendCallbackAnswer($callback_query->getId(), 'Рейтинг збережено');
                        }
                    }
                }
                case 'show_card':
                case 'deploy':
                {
                    $command_param = explode(',', $command_val);
                    $type = $command_param[0];
                    $data_id = $command_param[1];

                    $advert =  BaseModel::getThisByTypeID($type, $data_id, $command_param[2]);
                    # TODO переписати це все нахуй
                    if(!$advert){
                        throw new TelegramException("Об'єкт не знайдено!");
                        # TODO видалити оголошення при відсутності об'єкту ?!!
                    }
                    $card_type = str_replace('_tg', '', $type) . 'Card';
                    $params["category_type"] = $command_param[2];
//                    $category_type = strtolower($params["category_type"]);
//                    $params["category"] = substr($category_type, 0,4);
//                    $params["type"] = str_replace($params["category"], '', $category_type);
//                    $params["currency"] = $params["category"] == 'rent' ? 'UAH' : 'USD';


                    if ($command_param[4] == 'as_advert'){
                        $params["is_presentation"] = $command_param[3] == 'is_p' ? true : false;
                        $params["chat_id"] = $user_id;

                        $card_message = $advert->getPost($params);

                    }else {
                        $card_message = $advert->getTGCard($user_id, $params);
                    }

                    if($command == 'show_card') {
                        $card_message["chat_id"] = $card_message["chat_id"] ?? $user_id;
                    }elseif ($inline_message) {
                        $card_message['message_id'] = $message_id;
                        $card_message['chat_id'] = $chat_id;
                    } else {
                        $card_message['inline_message_id'] = $callback_query->getInlineMessageId();
                    }
                    return Cards::showCard($card_message, $command, $callback_id);

                    break;
                }
                case 'doNothing':{
                    // заглушка для клавіш, які нічого не мають виконувати
                    break;
                }


                default:
                    break;
            }
            return $result;
        }

        $inline_keyboard[] = array(
            new InlineKeyboardButton(['text' => 'Inline Query (current chat)', 'switch_inline_query_current_chat' => 'inline query...']),
            new InlineKeyboardButton(['text' => 'Inline Query (other chat)', 'switch_inline_query' => 'inline query...']));
        $inline_keyboard[] =
            array(new InlineKeyboardButton(['text' => 'Callback', 'callback_data' => 'send']),
            new InlineKeyboardButton(['text' => 'Open URL', 'url' => 'https://github.com/php-telegram-bot/example-bot']));

        $inline_keyboard = new InlineKeyboard(...$inline_keyboard);

        return Request::sendMessage(
            [
                'chat_id' => 522625209,
//                 'inline_message_id'        => $this->manager->getId(),
                'text'                     => '<b>The <code> inlinekeyboard </code> command is intended to respond to a simple button command</b>' . PHP_EOL . PHP_EOL,
                'reply_markup'             => $inline_keyboard,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]
        );
    }catch (LocalTGException $e) {
        Messages::sendCallbackAnswer($callback_id, $e->getMessage(), true);
        return Request::emptyResponse();
    }}

    protected function answerCallbackQuery(string $text = null, bool $alert = false): ServerResponse
    {
        if ($callback_query = $this->getUpdate()->getCallbackQuery()) {

            return Request::answerCallbackQuery(
                array(
                    'callback_query_id' => $callback_query->getId(),
                    'text'              => $text,
                    'show_alert'        => $alert,
                )
            );
        }

        return Request::emptyResponse();
    }
}