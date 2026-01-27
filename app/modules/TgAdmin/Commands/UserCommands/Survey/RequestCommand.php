<?php

namespace Modules\TgAdmin\Commands\UserCommands;

use Modules\TgAdmin\Models\Company\Employees;

use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\KeyboardButton;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;

use Modules\TgAdmin\Models\Person\ColdPhones;
use Modules\TgAdmin\Commands\MyUserCommand;
use Modules\TgAdmin\Featuring\Messages;
use Modules\TgAdmin\Models\Request\Requests;
use Modules\TgAdmin\Renderer\Buttons;
use Modules\TgAdmin\Renderer\RequestCardBuilder;
use TelegramBot\InlineKeyboardPagination\Exceptions\InlineKeyboardPaginationException;
use TelegramBot\InlineKeyboardPagination\InlineKeyboardPagination;

/**
 * request command
 */
class RequestCommand extends MyUserCommand
{
    protected $search_id;

    protected function constructor()
    {

        $this->name = 'request';
        $this->description = 'Request command';
        $this->usage = '/request';
        $this->version = '1.0.0';
        $this->is_button_next = false;
        $this->model = Requests::class;


        parent::constructor();
    }

    protected function preStart($params = array()){
        if($this->notes["lead_id"]) {
            $lead = ColdPhones::findFirst("id = '" . $this->notes["lead_id"] . "'");
            $this->notes["description"] = $this->notes["description"] ?? $lead->description . PHP_EOL;
            $this->notes["search_name"] = $this->notes["search_name"] ?? $lead->name;
            $this->notes["contact_phone"] = $this->notes["contact_phone"] ?? $lead->phone;
        }
    }

    protected function setNextState($pre_state){

        switch ($pre_state) {
            case 'start':
                $this->notes['state'] = "category";
                break;
            case 'category':
                $this->notes['state'] = 'type';
                break;
            case 'type':

//                if(!$this->notes['type']){$this->notes['state'] = 'type';}
                if($this->notes['type'] == 'flat' || $this->notes['type'] == 'house') {
//                    $notes['state'] = 'is_new';
                    $this->notes['state'] = 'city';
                }elseif($this->notes['type'] == 'ground' || $this->notes['type'] == 'commerce'){
//                    $notes['state'] = 'object_type';
                    $this->notes['state'] = 'city';
                }
                break;
//            case 'is_new':
//                if($notes['type'] == 'house' || ($notes['type'] == 'flat' && $notes['is_new'] == 'Ні')) {
//                    $notes['state'] = 'object_type';
//                }elseif(!$notes['is_new'] || ($notes['type'] == 'flat' && $notes['is_new'] == 'Так') ||  $notes['is_new'] == 'Не важливо'){
//                    $notes['state'] = 'region';
//                }
//                break;
//            case 'object_type':
//                $notes['state'] = 'region';
//                break;
            case 'city':
                if (isset($this->pages_data['region'][$this->notes['city']])) {
                    $this->notes['state'] = 'region';
                }else{
                    if($this->notes['type'] == 'ground'){
                        $this->notes['state'] = 'square_ground';
                    }else{
                        $this->notes['state'] = 'rooms';
                    }
//                    $this->notes['state'] = 'street';
                }
                break;
            case 'region':
                if($this->notes['type'] == 'ground'){
                    $this->notes['state'] = 'square_ground';
                }else{
                    $this->notes['state'] = 'rooms';
                }
                break;
            case 'rooms':
                if($this->notes['type'] == 'flat' || $this->notes['type'] == 'commerce') {
                    $this->notes['state'] = 'floor';
                }elseif($this->notes['type'] == 'house'){
//                    $this->notes['state'] = 'floors';
                    $this->notes['state'] = 'square';
                }
                break;
            case 'floor':
                if($this->notes['type'] == 'flat') {
//                    $this->notes['state'] = 'floors';
                    $this->notes['state'] = 'square';
                }elseif($this->notes['type'] == 'commerce'){
                    $this->notes['state'] = 'square';
                }
                break;
            case 'square':
                if($this->notes['type'] == 'flat' || $this->notes['type'] == 'commerce'){
                    $this->notes['state'] = 'price';
                }
                elseif($this->notes['type'] == 'house') {
                    $this->notes['state'] = 'square_ground';
                }
                break;
            case 'square_ground':
                $this->notes['state'] = 'price';
                break;
            case 'price':
                $this->notes['state'] = 'search_name';
                break;
//            case 'floors':
//                $notes['state'] = 'square';
//                break;
//            case 'address':
//                $notes['state'] = 'photos';
//                break;
            case 'search_name':
                $this->notes['state'] = 'description';
                break;
            case 'description':
                $this->notes['state'] = 'is_private';
                break;
            case 'is_private':
                $this->notes['state'] = 'end';
                break;
            case 'end':
                //               $this->notes['state'] = 'send';
                break;
            case 'send':
//                $this->conversation->stop();
//                $this->notes['send'] = 'stop';
//                return Request::emptyResponse();
                break;
            default:
                $this->conversation->cancel();
                $data['text'] = "Некорректна команда! Зверніться до адмінінстратора.";
                $data['reply_markup'] = Keyboard::remove(['selective' => true]);
                return Request::sendMessage($data);
                break;
        }
    }

    protected function end($default_data = array()){

        $employee = Employees::findFirst("user_id = '" . $this->user_id . "'");
        #TODO create chanel for requests and public it

        $default_data["search_id"] = $default_data["search_id"] ?? uniqid("", false);
        $default_data["author_id"] = $default_data["author_id"] ?? $this->user_id;
        $default_data["accountable_id"] = $default_data["accountable_id"] ?? $this->user_id;
        $default_data["company_id"] = $default_data["company_id"] ?? $employee->company_id;

        $default_data["contact"] = $this->notes["contact_phone"] ?? '-';
        $default_data["source"] = $default_data["lead_id"] ? 'lead' : 'realtor';
        $default_data["date"] = $default_data["date"] ?? date('Y-m-d H:i:s');
        $default_data["is_archive"] = $default_data["is_archive"] ?? false;
        $default_data["is_new"] = $default_data["is_new"] ? 1 : 0;

        $request_id = parent::end($default_data);

        if($this->notes['lead_id'] && $request_id){
            $lead = ColdPhones::findFirst("id = '" . $this->notes['lead_id'] . "'");
            $lead->status = 'in_work';
            $lead->request_id = $request_id;
            $lead->save();
        }
        return true;
            // надіслати його відправнику з інструкціями

        $inline_keyboard_menu = array(
            array(
                new InlineKeyboardButton(
                    [
                        'text' => 'Знайти об\'єкт',
                        'switch_inline_query_current_chat' => $search_model->search_id
                    ]
                ),
                new InlineKeyboardButton(
                    [
                        'text' => 'Знайти об\'єкт ⤴',
                        'switch_inline_query' => $search_model->search_id
                    ]
                ),
            ),
            array(
                new InlineKeyboardButton(['text' => '❌ ' . TG_CLOSE, 'callback_data' => 'inlinekeyboard;close']),
            )
        );
        $result = Request::sendMessage(
            [
                'chat_id' => $this->chat_id,
                'text' => 'ID вашого оголошення - ' . $this->notes["object_id"] . PHP_EOL
//                    . 'опубліковано в групі ' . $chat_id . PHP_EOL
                    . 'Що зробити з оголошенням?',
                'reply_markup' => new InlineKeyboard(...$inline_keyboard_menu),
            ]
        );
        if(!$result->isOk()){
            throw new TelegramException('Final submit message error: '. $result->getDescription());
        }

//            return Request::emptyResponse();
//            $this->conversation->stop();
        return true;

    }

    private function editMessage($text, $page, $chat_id, $message_id){

        $result = Request::editMessageText(
            [
                'chat_id'                  => $chat_id,
                'message_id'               => $message_id,
                'text'                     => $text,
//                'reply_markup'             => $inline_keyboard,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]
        );
        if(!$result->isOk()){
            return Request::sendMessage(array(
                'text'    => $result,
                'chat_id' => 522625209
            ));
        }
        return $result;
    }
    protected function getInlineData($params = array()): string{
        $result = array();
//        Messages::sendMeMessage("test1");
        $result["title"] = $this->getTittle((array)$this);
//        Messages::sendMeMessage("test2");
        $result["description"] = $this->getDescription();
//        Messages::sendMeMessage("test3");
        if($thumbURL = $this->getThumbUrl()) {
            $result["thumb_url"] = str_contains($thumbURL, "https:") ? $thumbURL : DOMAIN_NAME . $thumbURL;
        }
        Messages::sendMeMessage("test4");
        $TGCard = $this->getTGCard($params["user_id"], $params);
        Messages::sendMeMessage("test5");exit;
        return $result + $TGCard;
    }
    protected function getReplyMarkup(){

        $page = $this->notes['state'];

        if (!$page) {
            return Buttons::getInlineKeyboard(Buttons::getSurveyMenu($this->name, $page, $this->notes[$page]));
        }
        $no_key = array('square','square','square_ground','description','price','search_name');

        switch ($page) {
            case 'send':
            {
                return Buttons::getInlineKeyboard( array(
                    Buttons::getButton('Пошук в своєму чаті',  $this->search_id, 'sc' ),
                    Buttons::getButton('Пошук у вибраному чаті', $this->search_id, 's')
                ));
            }
            case 'object_type':
            {
                $page =  'object_type/' . $this->notes['type'];
                break;
            }
            case 'region':
            {
                $page =  'region/' . $this->notes['city'];
                break;
            }
        }

//Messages::sendMeMessage(json_encode(Buttons::getPageButtons($this->name, $page), JSON_UNESCAPED_UNICODE));
//Messages::sendMeMessage(json_encode(Buttons::getSurveyMenu($this->name, $page, $this->notes[$page])));
        return Buttons::getInlineKeyboard(array_merge(
            in_array($page, $no_key) ? array() : Buttons::getPageButtons($this->name, $page),
            Buttons::getSurveyMenu($this->name, $page, $this->notes[$page])
        ));

//        return Buttons::getInlineKeyboard($inline_keyboard);
    }


    protected function getMessageData($notes):array{
        $card = new RequestCardBuilder($notes);

        $text = $card->getPreOutMessage(["search_id" => $this->search_id]);
        $reply_markup = $this->getReplyMarkup();

        if(!$text){ throw new TelegramException('Can\'t send message. Text is empty.');}
        if(!$reply_markup){ throw new TelegramException('Can\'t send message. The buttons group is empty.');}
//        if(!($reply_markup instanceof InlineKeyboard)){ throw new TelegramException('Can\'t send message. Wrong buttons group.');}

        return array(
            'text' => $text,
            'parse_mode' => 'HTML',
            'reply_markup' => $reply_markup,
//            'disable_web_page_preview' => true,
        );
    }
}