<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Modules\TgAdmin\Commands;


use common\exceptions\DBException;
use Longman\TelegramBot\Commands\Command;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\ConversationDB;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

use Modules\TgAdmin\Models\Estate\Objects;
use Modules\TgAdmin\Renderer\Buttons;
use Modules\TgAdmin\Renderer\Messages;
use Modules\TgAdmin\Renderer\Translator;

use TelegramBot\InlineKeyboardPagination\InlineKeyboardPagination;
use TelegramBot\InlineKeyboardPagination\Exceptions\InlineKeyboardPaginationException;


abstract class MyUserCommand extends Command
{
//    private $name;
//    protected $description;
//    protected $usage;
//    protected $version;

    protected $conversation;
    protected $notes;
    protected $is_button_next;
//
    protected $params;
    protected $pages_data;
    protected $page_messages;
    protected static $translate_data = array(
        'Продаж' => 'Продається',
        'Оренда' => 'Здається в оренду',
        'Квартира' => 'квартира',
        'Будинок' => 'будинок',
        'Ділянка' => 'ділянка',
        'Комерція' => 'комерція',
        'floor' => 'поверх',
        'square' => 'площа',
        '1к' => '1 кім.',
        '2к' => '2 кім.',
        '3к' => '3 кім.',
        '4к' => '4 кім.',
        '5к+' => '5+ кім.',
//        'Продаж'      => 'продаж',
//        'Оренда'      => 'оренду',
//        'Квартира'    => 'квартира',
//        'Будинок'     => 'будинок',
//        'Ділянка'    => 'ділянка',
//        'Комерція'  => 'комерція',
//        'floor'     => 'поверх',
//        'square'    => 'площа',
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
        '3r'        => 'Не останній',
    );

    protected $providerModels;

    protected $chat_id;
    protected $user_id;
    protected $user_name;
    protected $message;
    protected $message_id;
    protected $input_text;
    protected $callback_query;
    protected $callback_query_data;
    protected $key_data;
    protected $model;
    protected $service;


    protected function constructor(){

        $this->params = array(
            'category' => "/Оренда|Продаж/",
            'type' => "/Квартира|Будинок|Ділянка|Комерція/",
            'city' => "",
            'region' => "", //"/Галицький|Шевченківський|Франківський|Сихівський|Залізничний|Личаківський/",
            'street' => '',
            'rooms'=> "/^[1-9]$/",
//            'floor' => "/Не перший|Від 5го|Від 10го|Останній|Перший|До 4го|До 9го|Не останній/",
            'floor' => "/(^[1-9]$)|(^([1-3])([0-9])$)|(^([4])([0])$)/",
            'floors' => "/(^[1-9]$)|(^([1-3])([0-9])$)|(^([4])([0])$)/",
            'material' => "",
            'is_new' => "/Так|Ні/",
            'square' => '/^((\d{1,4}\.\d{1,2})|(\d{1,4}))$/',
            'square_full' => '/^((\d{1,4}\.\d{1,2})|(\d{1,4}))$/',
            'square_dwelling' => '/^((\d{1,3}\.\d{1,2})|(\d{1,3}))$/',
            'square_kitchen' => '/^((\d{1,3}\.\d{1,2})|(\d{1,3}))$/',
            'square_ground'=> '/^((\d{1,6}\.\d{1,2})|(\d{1,6}))$/',
            'object_type'=> "/Чешка|Сталінка|Хрущовка|Австрійка|Малосімейка|Окремостоячий|Спарка|Котедж|Дача|Офіс|Магазин|Склад|Виробництво|Під будівництво|Під СГ|Житлови фонд 91-13/",
            'condition'=> '/0-цикл|потребує ремонту|житловий стан|косметичний ремонт|євроремонт|дизайнерський ремонт/',
            'wall_material'=> '/цегла|панель|газоблок|бетон|дерево|піноблок/',
            'description'=> '',
            'add_description'=> '',
            'price'=> '/^\d{3,8}$/',
            'set_phones' => '',
            'message' => "",
//            'set_phones' => '/^((\d{10}), ?)*(\d{10})$/',
            'showing_at_time' => '/(^[0-9]$)|(^[1][0-9]$)|(^[2][0-3]$)|(^[1-9]\.[0-9]$)|(^[1-9]\.[0-5][0-9]$)|(^[1][0-9]\.[0-9]$)|(^[1][0-9]\.[0-5][0-9]$)|(^[2][0-3]\.[0-9]$)|(^[2][0-3]\.[0-5][0-9]$)/',
            'contact_phone_in'=> '/^\+\d{10,15}$/', // international
            'contact_phone_ua'=> '/^0\d{9}$/', // ukrainian
            'contact_name'=> '',
            'search_name' => '',
            'first_name' => "",
            'list_name' => '',
            'location' => '',
            'address' => '',
            'photos' => '',
            'start' => "",
            'name' => "",
            'subject_type' => '',
            'subject_id' => '',
            'last_name' => "",
            'receiver' => "",
            'where_from' => "",
            'what_need' => "",
            'what_can' => "",
            'who_are' => "",
            'status' => "",
            'phone' => '/^0\d{9}$/',
            'photo' => '',
            'send' => '',
            'url' => '',
            'end' => '',

        );

        $this->message = $this->getMessage();
        $this->callback_query = $this->getCallbackQuery();
        $chosen_inline_result = $this->getChosenInlineResult();
        if (!empty($this->message)) {

//            $this->command = trim($this->message->getText(false));
            $this->input_text = trim($this->message->getText(false));
            $this->user_id = $this->message->getFrom()->getId();
            $this->user_name = $this->message->getFrom()->getUsername();
        }
        elseif (!empty($this->callback_query)) {
            $this->message = $this->callback_query->getMessage();
            $this->callback_query_data =  $this->callback_query->getData();

            $this->key_data = explode(";", $this->callback_query_data);
            $this->user_id = $this->callback_query->getFrom()->getId();
            $this->user_name = $this->callback_query->getFrom()->getUsername();

        }else{
            Messages::sendMessage($this->user_id, 'none');
        }

        if(isset($this->message)){
            $this->message_id = $this->message->getMessageId();
            $this->chat_id = $this->message->getChat()->getId();
        }else{
            $this->chat_id = $this->user_id;
        }
    }

    public function execute(): ServerResponse{
        try {
            $this->constructor();

            // використання логера
//            $this->logger->info('Користувач стартував бота', ['user_id' => $userId]);
//            $this->logger->debug('Отримані дані Telegram', $telegramData);
//
//            try {
//            } catch (\Throwable $e) {
//                $this->logger->error('Помилка при реєстрації', $e);
//            }

            // Check the message was sent from a public group or channel
            if (isset($this->message) && $this->isGroupMessage()) {
                return Request::emptyResponse();
            }

            // Conversation start
//            $this->conversation = new Conversation($this->user_id, $this->chat_id, $this->getName());
//            $this->notes = &$this->conversation->notes;
            if(!$this->setConversation()){
                self::errorMessage($this->chat_id, 'Команда закрита.');
                return Request::emptyResponse();
            }

            // detect
            if ($command = $this->isCommand()) {
                if (!$this->doCommand($command)) {
                    return Request::emptyResponse();
                }
            }else {
                if (!$this->setParam()) {
                    return Request::emptyResponse();
                }
            }

            if (!($pre_state = $this->getLastPreState())) {
                throw new TelegramException('Can\'t init pre_state');
            }

            $this->setNextState($pre_state);
            $result = $this->sendMessageTg();
            $this->notes['pre_state'] = $this->getNewPreState();

            if(!$this->conversation->update()) {
                Messages::sendMeMessage('Conversation is not updated');
            }
            return $result;
        } catch (TelegramException $e) {
            $message = "<b>Користувач: </b>" . (Messages::getTittle('user', $this->user_id) ?? $this->user_id);
            return Messages::sendMeMessage($e->getMessage());
        }
    }

//    abstract protected function preStart();
    abstract protected function setNextState($pre_state);
    abstract protected function getMessageData($notes);

    protected function sendMessageTg($id = null){

        $data = $this->getMessageData($this->notes);
        $data['chat_id'] = $id ?? $this->chat_id;

        if(isset($this->notes['q_message_id'])) {
            $data['message_id'] = $this->notes['q_message_id'];
            $end_request = Request::editMessageText($data);
//            if (!$request->isOk() && $request->getErrorCode() != 400) {
        }
        if (!isset($end_request) || !$end_request->isOk()) {
            $end_request = Request::sendMessage($data);
            $this->notes['q_message_id'] = $end_request->isOk() ? $end_request->getResult()->message_id : '';
        }
        if(!$end_request->isOk()){
            throw new TelegramException(
                'Повідомлення не надіслано:' . PHP_EOL .
                $end_request->getDescription()
            );
        }
        return $end_request;
    }

    protected function isGroupMessage(){
        if (($this->message->getChat()->isGroupChat() || $this->message->getChat()->isSuperGroup()) && $this->usage.'@EstateBookBot' == $this->message->getText()) {
//        if ($this->message->getChat()->isGroupChat() || $this->message->getChat()->isSuperGroup()) {

            $request = Request::sendMessage(
                [
                    'chat_id' => $this->chat_id,
                    'text'    => "Команду " . $this->usage . " можна використовувати лише в приватному чаті з @EstateBookBot",
//                    'reply_markup' => Keyboard::forceReply(['selective' => true])
                ]
            );

            sleep(8);
            Request::deleteMessage([
                'chat_id' => $this->chat_id,
                'message_id' => $this->message_id
            ]);
            Request::deleteMessage([
                'chat_id' => $this->chat_id,
                'message_id' => $request->getResult()->message_id
            ]);
            return true;
        }
        return false;
    }

    protected function setConversation(){

        $conversation = ConversationDB::selectConversation($this->user_id, $this->chat_id, 1);

        if (isset($conversation[0]) && $conversation[0]['command'] != $this->name) {
            #TODO create question: close previous command?
            $t_notes = json_decode($conversation[0]['notes'], JSON_UNESCAPED_UNICODE);
            if($t_notes["q_message_id"]){
                Request::deleteMessage([
                    'chat_id'      => $this->chat_id,
                    'message_id'   => $t_notes["q_message_id"]
                ]);
            }
        }
//        $this->conversation = new Conversation($this->user_id, $this->chat_id, $this->getName());
        $this->conversation = new Conversation($this->chat_id, $this->chat_id, $this->getName());
//        if($this->conversation->load()){}
        // Load any existing notes from this conversation

        $this->notes = &$this->conversation->notes;

        if($this->notes === false){
            return false;
            throw new TelegramException('Notes can\'t be loaded' );
        };

        if (isset($this->notes['o_message_id']) ) {
            Request::deleteMessage([
                'chat_id' => $this->chat_id,
                'message_id' => $this->notes['o_message_id']
            ]);
            $this->notes['o_message_id'] = NULL;
        }
        return true;
    }

    protected function isCommand()
    {
        if (isset($this->callback_query_data)){
            if(!isset($this->key_data[1])){
                return 'start';
            }
            if($this->key_data[1] == 'command') {
                return $this->key_data[2];
            }
        }

//        $message_data = $message->getRawData();\
        if (!empty($this->getMessage()) && preg_match("/(^\/)/", $this->input_text)) {
            return $this->input_text == $this->usage ? 'start' : false;
        }
        return false;
    }

    protected function doCommand($command){

//        $command = $this->input_text == $this->usage ? 'start' : $this->key_data[2];
        $data_type = $this->key_data[3];
        $data_id = (int)$this->key_data[4];
        $data_param1 = $this->key_data[5];
        $data_param2 = $this->key_data[6];

        $this->notes[$data_type] = $data_id;
        $this->notes["data_type"] = $this->notes["data_type"] ??  $data_type;
        $this->notes["data_id"] = $this->notes["data_id"] ?? $data_id;

        switch ($command){
            case "edit":{
                if(!$data_type || !$data_id) {
                    throw new TelegramException('Не можу знайти дані, які потрібно змінити по команді ' . $command);
                }

                $this->notes = $this->model::getDataArray($data_id);
                if($data_param1) {
                    $this->notes["edit_id"] = $data_id;
                    $this->notes['state'] = $data_param1;
                    $this->notes['pre_state'] = json_encode([$data_param1]);
                    return true;
                }
            }
            case "start":{

                if($command == 'start' && $data_id) {
//                    $this->notes['data_param'] = $data_param1;
                    if($this->preStart(['param1' => $data_param1, 'param2' => $data_param2]) === false) return false;
                    $this->notes["static_data"] = 1;
                }

                if(!isset($this->notes['state']) || !isset($this->notes['pre_state'])) {

                    $this->notes['state'] = "start";//"category";
                    $this->notes['pre_state'] = json_encode(["start"]);
                }else{
                    // todo send message: finish your current command first!
                    $preStateArray = json_decode($this->notes['pre_state'], JSON_OBJECT_AS_ARRAY );
                    $this->notes['state'] = array_pop($preStateArray);
                    $this->notes['pre_state'] = json_encode($preStateArray);
                    Request::deleteMessage([
                            'chat_id'      => $this->chat_id,
                            'message_id'   => $this->notes["q_message_id"]
                        ]
                    );
                }

                return true;
                break;
            }
            case "prev":{
                $lastPreState = $this->getLastPreState();
                if($lastPreState == 'start'){
                    $this->replyToChat('Це стартова сторінка.');
                    return false;
                }
                $preStateArray = json_decode($this->notes['pre_state'], JSON_OBJECT_AS_ARRAY );
                array_pop($preStateArray);
                $this->notes['state'] = array_pop($preStateArray);
                $this->notes['pre_state'] = json_encode($preStateArray);
                return true;
                break;
            }
            case "next":{
//                if( $this->notes['state'] == "category" ||  $this->notes['state'] == "type"  ||  $this->notes['state'] == "end"  ) {return false;}
                return true;
                break;
            }
            case "clear":{
                $this->notes[$this->notes['state']] = null;
                $this->notes[$this->notes['state'].'_from'] = null;
                $this->notes[$this->notes['state'].'_to'] = null;
                return true;
                break;
            }
            case "search":
            {

                $message_id = $this->key_data[5];
                if (isset($message_id)) {
                    $page = $this->key_data[4];
                } else {
                    $page = 1;
                }

//                $this->notes['source'] = str_replace('search', '', $this->name);
                $this->notes['source'] = 'objects';
                $this->notes['rooms'] = null;
                $this->notes['price'] = null;

                $search_result = \Realty\Realty::searchRealty($this->notes, $page, 5);

                $text = 'Результат пошуку: ' . PHP_EOL;
                foreach ($search_result as $object) {
                    $object = $object->toArray();

                    $object['category'] = $this->notes['category'];
                    $object['type'] = $this->notes['type'];
                    $text .= PHP_EOL . PHP_EOL . Objects::getTGText($object);
                }
                if (isset($message_id)) {
//                Request::sendMessage(
//                    [
//                        'chat_id' => $this->chat_id,
//                        'text' =>$text? 't':'f'
//                    ]
//                );
                    Request::editMessageText([
                        'message_id' => $message_id,
                        'chat_id' => $this->chat_id,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $this->createPagination($page)
                    ]);
                } else {
                    Request::sendMessage([
                        'chat_id' => $this->chat_id,
                        'text' => $text,
                        'parse_mode' => 'HTML',
                        'reply_markup' => $this->createPagination($page)
                    ]);
                }
                return false;
                break;
            }
            case "dialog":{
                $this->notes["o_message_id"] = Messages::dialogWindow(
                    'Ви дійсно хочете завершити команду?',
                    $this->chat_id,
                    $this->name . ';command;' . $this->key_data[3]
                );
                if($this->notes["o_message_id"]){
                    $this->conversation->update();
                }
                return false;
                break;
            }
            case "calendarCallback":
            {
//                $lastPreState = $this->getLastPreState();
                $preStateArray = json_decode($this->notes['pre_state'], JSON_OBJECT_AS_ARRAY );
                $this->notes['state'] = array_pop($preStateArray);
                $this->notes['pre_state'] = json_encode($preStateArray);
                $this->notes['show_date'] = $this->key_data[3];
                return true;
                break;
            }
            case "end":{ // end should be before exit
                $end = $this->end();
            }
            case "exit":{
                return $this->exitMe($end ?? false);
                break;
            }
            default:{
                throw new TelegramException('Can\'t do command');
                break;
            }
        }
    }

    protected function validParams($preState, $text)
    {
        if(!isset($this->params[$preState])){
            throw new TelegramException('Parameter ' . $preState . ' not found! Contact your administrator.');
        }
        elseif ($this->params[$preState] === ''){return true;}

        return preg_match($this->params[$preState] , $text);
    }

    protected function setParam()
    {
        $lastState = $this->getLastPreState();
        $text_answer = isset($this->input_text) ? $this->input_text : null;
        $inline_answer = isset($this->key_data) ? $this->key_data[2] : null;

        if($this->message->getViaBot()){return false;}
        if (!$lastState) { return false; }

        if ($lastState == 'floors' && ((int)$this->notes['floor'] > (int)$inline_answer)){

            Request::answerCallbackQuery(
                array(
                    'callback_query_id' => $this->callback_query->getId(),
                    'text' => 'Поверховість повинна бути не меншою за поверх.'
                )
            );
            return false;
        }  //похереться, якщо додати в searchObject floors
        if ($lastState == 'city'){
            $this->notes['region'] = NULL;
        }
        if ($lastState == 'contact_name' && $inline_answer == "current name") {return true;}
        if ($lastState == 'end' && $inline_answer == 'send object'){ return true;}

        if($text_answer) {
            Request::deleteMessage([
                'chat_id' => $this->chat_id,
                "message_id" => $this->message_id
            ]);
            $no_text_answer = array('category', 'type', 'region', 'floors', 'floor', 'beds', 'city', 'photo', 'status');
            if (in_array($lastState, $no_text_answer)) {
                self::errorMessage($this->chat_id, 'Використовуйте кнопки');
                return false;
            }
        }

        $from_to_answer = array('rooms', 'floor', 'square', 'square_ground', 'price');
        if(in_array($lastState, $from_to_answer)){
            return $this->_set_param( $lastState, $text_answer, $inline_answer);
        }

        if ($photo = $this->message->getPhoto()) {
            $result = false;
            $this->notes["media_group_id"] = $this->message->getMediaGroupId() ?? '';

            // init conversation
            $photo_id = end($photo)->getFileId();
            $this->notes["photos_id"][] = $photo_id;

            if (!isset($this->notes["photos"])) {
                $result = true; // if true next step will go automatically
            }
            $this->notes["photos"] = count($this->notes["photos_id"]);

            //download new image
            $ServerResponse = Request::getFile(['file_id' => $photo_id]);
            if ($ServerResponse->isOk()) {

                if (Request::downloadFile($ServerResponse->getResult())) {
//                        $photo_uri = $this->getTelegram()->getDownloadPath() . '/' . $ServerResponse->getResult()->file_path;
//                        $this->notes['photo_url'] = EstateObject::sendPhotoToStorage($photo_uri);

                    $this->notes['photos_url'][] = '/TelegramFiles/Download/' . $ServerResponse->getResult()->file_path;
                    $this->conversation->update();
                }else{
                    throw new TelegramException('Не можу завантажити зображення.');
                }
            }else{
                throw new TelegramException('Не можу отримати посилання на зображення.');
            }

            Request::deleteMessage([
                'chat_id' => $this->chat_id,
                'message_id' => $this->message_id
            ]);
            return $result;
        }
        elseif ($document = $this->message->getDocument()) {
            $mime_type = $document->getMimeType();
            if (strpos($mime_type, 'jpeg') || strpos($mime_type, 'png')) {
                $this->notes["photos_id"][] = $document->getFileId();
                return true;
            }
            // should be exception
            $this->replyToChat('Фото меє бути у форматі *.jpg або *.png . Також поставте галочку навпроти пункту "Стиснути зображення"');
            return false;
        }

        if ($lastState == 'contact_phone') {
            if ($contact = $this->message->getContact()) {
                // choose phone number from text and validate it or if empty, get it from contact cvv

                $this->notes['contact_phone'] = $contact->getPhoneNumber();
                $this->notes['contact_name'] = $contact->getFirstName();
//            $this->notes['contact_id'] = $contact->getUserId();

                if ($this->user_id == $contact->getUserId()) {
                    $this->notes['user_name'] = $this->user_name;
                };

                Request::deleteMessage([
                    'chat_id' => $this->chat_id,
                    'message_id' => $this->message_id
                ]);
            } elseif ($text_answer) {
                $text_answer = preg_replace('/[^\d+]/', '', $text_answer); // preg_replace все крім цифр і
                if ($this->validParams("contact_phone_in", $text_answer)){
                    $this->notes['contact_phone'] = $text_answer;
                }
                elseif($this->validParams("contact_phone_ua", $text_answer)){
                    $this->notes['contact_phone'] = '+38' . $text_answer;
                }
                else{
                    self::errorMessage($this->chat_id, 'Не вірний формат.');
                    return false;
                }
            }
            else{
                return false;
            }
            return true;
        }
        elseif ($lastState == 'location' && $location = $this->message->getLocation()){
            $this->notes['location'] = 'Address';
            $this->notes['latitude'] = $location["latitude"];
            $this->notes['longitude'] = $location["longitude"];
            return true;
        }
        elseif ($lastState == 'reminder_at' || $lastState == 'showing_at'){
            $this->notes[$lastState] = $inline_answer . ($this->notes[$lastState . "_time"] ? ' ' . $this->notes[$lastState . "_time"] : '');
            return true;
        }
        elseif ($lastState == 'reminder_at_time'){

            $t_state = str_replace('_time', '', $lastState);
            $this->notes[$lastState] = $inline_answer . ':00';
            $this->notes[$t_state] = explode(' ', $this->notes[$t_state])[0] . ' ' . $this->notes[$lastState];
            return true;
        }
        elseif ($lastState == 'showing_at_time'){
            if($this->validParams($lastState, $text_answer)){
                $time = explode('.', $text_answer);
                $hour = (int) $time[0];
                $minute = isset($time[1]) ? (int) $time[1] : 0;

                if($hour < 8){
                    $hour += 12;
                }
                $this->notes[$lastState] = $hour . ':' . $minute . ':00';
                $t_state = str_replace('_time', '', $lastState);
                $this->notes[$t_state] = explode(' ', $this->notes[$t_state])[0] . ' ' . $this->notes[$lastState];
                return true;
            }
            else {
                Messages::sendInfoMessage($this->user_id, 'Введіть корректнв значення!');
                return false;
            }

        }
        elseif ($lastState == 'category') {
            if ($inline_answer == "rent") {
                $this->notes["currency"] = 'UAH';
            } else {
                $this->notes["currency"] = 'USD';
            }
        }
        elseif ($lastState == 'last_name' && isset($inline_answer)) {
            $this->notes["first_name"] = trim(str_replace($inline_answer, '' , $this->notes["first_name"]));
            $this->notes["last_name"] = $inline_answer;
        }
//
//        Request::sendMessage(
//            [
//                'chat_id' => 522625209,
//                'text' => isset($inline_answer) ? 't' : 'f'
//            ]
//        );
        if(isset($inline_answer)){
            $answer = $inline_answer;
        }
        elseif(isset($text_answer)){
            if($this->validParams($lastState, $text_answer)) {
                $answer = $text_answer;
            }else{
                throw new TelegramException('Введіть корректнв значення!');
            }
        }
        else{
            throw new TelegramException('Не вдалось ідентифікувати відповідь для "' . $lastState . '". Зверніться до адміністратора!');
        }

        $this->notes[$lastState] = $answer;
        return true;
    }

    private function _set_param($last_state, $text_answer, $inline_answer){
        if ($text_answer) {
            $error_text_arr = array(
                'square' => 'Формат:' . '(від)<b>xххх.хх</b> (до)<b>xххх.хх</b>' . PHP_EOL
                    . ' Приклади:'  . PHP_EOL
                    . '<b>   50 90' . PHP_EOL . '   0 45' . PHP_EOL . '   75' . PHP_EOL . '   56.12 89.65 </b>',
                'square_ground' => 'Формат:' . '(від)<b>ххх.хх</b> (до)<b>ххх.хх</b>' . PHP_EOL
                    . ' Приклади:'  . PHP_EOL
                    . '<b>   50 90' . PHP_EOL . '   0 45' . PHP_EOL . '   75' . PHP_EOL . '   56.12 89.65 </b>',
                'rooms' => 'Формат:' . '(від)<b>х</b> (до)<b>х</b>' . PHP_EOL
                    . ' Приклади:'  . PHP_EOL
                    . '<b>   1 3' . PHP_EOL . '   2' . PHP_EOL . '   4 9</b>',
                'floor' => 'Формат:' . '(від)<b>ххх.хх</b> (до)<b>ххх.хх</b>' . PHP_EOL
                    . ' Приклади:'  . PHP_EOL
                    . '<b>   50 90' . PHP_EOL . '   0 45' . PHP_EOL . '   75' . PHP_EOL . '   56.12 89.65 </b>',
                'price' => 'Формат:' . '(від)<b>ххxxxхx</b> (до)<b>xxxхххx</b>' . PHP_EOL
                    . ' Приклади:'  . PHP_EOL
                    . '<b>   50000 90000' . PHP_EOL . '   0 45000' . PHP_EOL . '   75000' . PHP_EOL . '   560000 1500000 </b>',
            );
            $param = explode(' ', trim($text_answer));

            if (!$this->validParams($last_state, $param[0])) {
                self::errorMessage($this->chat_id, $error_text_arr[$last_state],9);
                // TODO перенести errorMessage в меседжі
                return false;
            }

            if (isset($param[1])) {
                if (!$this->validParams($last_state, (int)$param[1])) {
                    self::errorMessage($this->chat_id, $error_text_arr[$last_state], 9);
                    return false;
                }
                if($param[0] > $param[1]) {
                    self::errorMessage($this->chat_id, 'Значення <b>ДО</b> менше значення <b>ВІД</b> .');
                    return false;
                }
            }
            $this->notes[$last_state] = $param[0];

            if($last_state == 'price' && !isset($param[1])){
                $this->notes[$last_state . "_to"] = $param[0];
                return true;
            }

            $this->notes[$last_state . "_from"] = $param[0];
            $this->notes[$last_state . "_to"] = $param[1];
            return true;
        }elseif($inline_answer){
            $param = explode(' ', $inline_answer);
            $this->notes[$last_state] = $param[0];
            $this->notes[$last_state . "_from"] = $param[0];
            if (isset($param[1])){
                $this->notes[$last_state . '_to'] = $param[1];
            }else{
                $this->notes[$last_state . '_to'] = null;
            }
            return true;
        }
        return false;
    }

    protected function getNewPreState():string {

        if(!isset($this->notes['pre_state'])){
            return json_encode(['start']);
        }

        $preStateArray = json_decode($this->notes['pre_state'], JSON_OBJECT_AS_ARRAY );
        if(end($preStateArray) == $this->notes['state']) {
            return $this->notes['pre_state'];
        }
        array_push($preStateArray, $this->notes['state']);
        return json_encode($preStateArray);
    }

    protected function createPagination($page = 1){

        $items        = range(1, 50); // required.
        $command      = $this->name; // optional. Default: pagination
        $selectedPage = $page;            // optional. Default: 1
        $labels       = [              // optional. Change button labels (showing defaults)
            'default'  => '%d',
            'first'    => '« %d',
            'previous' => '‹ %d',
            'current'  => '· %d ·',
            'next'     => '%d ›',
            'last'     => '%d »',
        ];

// optional. Change the callback_data format, adding placeholders for data (showing default)
//        $callbackDataFormat = 'command={COMMAND}&oldPage={OLD_PAGE}&newPage={NEW_PAGE}';
        $message_id = $page == 1 ? (int)$this->message_id + 1 : $this->message_id;
        $callbackDataFormat = '{COMMAND};command;search;{OLD_PAGE};{NEW_PAGE};' . $message_id;

        try {

            $ikp = new InlineKeyboardPagination($items, $command);
            $ikp->setMaxButtons(7, true); // Second parameter set to always show 7 buttons if possible.
            $ikp->setLabels($labels);
            $ikp->setCallbackDataFormat($callbackDataFormat);


//          Get pagination.
            $pagination = $ikp->getPagination($selectedPage);
//          or, in 2 steps.
//          $ikp->setSelectedPage($selectedPage);
//          $pagination = $ikp->getPagination();

            // Use it in your request.
            if (!empty($pagination['keyboard'])) {
                //$pagination['keyboard'][0]['callback_data']; // command=testCommand&oldPage=10&newPage=1
                //$pagination['keyboard'][1]['callback_data']; // command=testCommand&oldPage=10&newPage=7

                return array(
                    'inline_keyboard' => [
                        $pagination['keyboard'],
                        Buttons::getCloseButton()
                    ]
                );
            }

        }catch (InlineKeyboardPaginationException $e){
            Request::sendMessage(
                [
                    'chat_id' => 522625209,
                    'text'    => $e->getMessage()
                ]
            );
            return false;
        }
    }

    protected function getLastPreState(){
        $preStateArray = json_decode($this->notes['pre_state'], JSON_OBJECT_AS_ARRAY );
        if(is_array($preStateArray) && $preStateArray){
            return end($preStateArray);
        };
        return false;
    }


    protected function end2($default_data = array())
    {

    }
    protected function end($default_data = array()){
//        if(!($this->model instanceof Model)){
//            throw new TelegramException('Can\'t get Model class in ' . $this->usage );
//        }

        $main_service = $this->service;
        if(isset($this->notes["edit_id"])){
            $main_service = $this->service->findByID($this->notes["edit_id"]);
        }
        else{
            $this->notes += $default_data;
        }
        $this->notes["created_at"] = $this->notes["created_at"] ?? date('Y-m-d H:i:s');
        $this->notes["updated_at"] = date('Y-m-d H:i:s');
        $main_service->assign($this->notes);

        try{
            $id = $main_service->save();
        }catch (DBException $e){
            Messages::sendMeMessage($e->getMessage());
        }
        Messages::sendCallbackAnswer(
            $this->callback_query->getId(),
            $this->t("TG_DATA_SAVED"),
//            true
        );
        return $main_service->id;
    }

    protected function exitMe($end_function){

        $request = Request::deleteMessage([
                'chat_id'      => $this->chat_id,
                'message_id'   => ($this->notes["q_message_id"] ?? $this->message_id)
            ]
        );
        if(!$request->isOk()){
            throw new TelegramException(
                'Повідомлення не видалено:' . PHP_EOL .
                $request->getDescription()
            );
        }
        $this->conversation->stop();
        if(isset($this->callback_query)) {
            Request::answerCallbackQuery(
                array(
                    'callback_query_id' => $this->callback_query->getId(),
                    'text' => 'Завершено',
//                        'show_alert' => 'true',
                )
            );
        };

        switch ($end_function){
            case "company":{
                if($this->name == 'survey') {
                    $this->telegram->executeCommand('company');
                }
            }
        }
        return false;
    }

    static public function errorMessage($chat_id, $message, int $time = 4){
        $info_message = Request::sendMessage(
            [
                'chat_id' => $chat_id,
                'text' => '❗️' . $message,
                'parse_mode' => 'HTML'
            ]
        );
        sleep($time);
        Request::deleteMessage([
            'chat_id' => $info_message->getResult()->getChat()->id,
            "message_id" => $info_message->getResult()->message_id
        ]);
    }

    protected function t($text): string { return Translator::t($text); }
}
