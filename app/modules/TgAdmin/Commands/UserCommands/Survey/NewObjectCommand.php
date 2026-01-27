<?php

namespace Modules\TgAdmin\Commands\UserCommands;

use Modules\TgAdmin\Featuring\Messages;
use Modules\TgAdmin\Models\Estate\Objects;
use Modules\TgAdmin\Models\Featuring\BaseModel;
use Modules\TgAdmin\Models\Featuring\ReObjects;


use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\KeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Modules\TgAdmin\Renderer\Buttons;
use Modules\TgAdmin\Renderer\ObjectCardBuilder;
use Realty\RealtyReamak;
use Modules\TgAdmin\Commands\MyUserCommand;


/**
 * Submit command
 */
//class SubmitCommand extends MyUserCommand
class NewObjectCommand extends MyUserCommand
{
    protected function constructor()
    {
        $this->name = 'new_object';
        $this->description = 'Create new realty object';
        $this->usage = '/new_object';
        $this->version = '3.0';
        $this->is_button_next = false;
        $this->model = Objects::class;

        parent::constructor();
    }

    protected function preStart($params = array()){

        $user = Objects::getDataArray($this->user_id);

        $this->notes += $user;
        $this->notes["user_id"] = $this->user_id;
        $this->notes["user_name"] = $user["username"];
        $this->notes["contact_phone"] = $user["contact_phone"];
        $this->notes["contact_name"] = $user["first_name"] . ' ' . $user["last_name"];
    }

    protected function setNextState($pre_state)
   {
       switch ($pre_state) {
           case 'start':
               $this->notes['state'] = "category";
               break;
           case 'category':
               $this->notes['state'] = 'type';
               break;
           case 'type':
               if ($this->notes['type'] == 'flat' || $this->notes['type'] == 'house') {
                   $this->notes['state'] = 'is_new';
               } elseif ($this->notes['type'] == 'ground' || $this->notes['type'] == 'commerce') {
                   $this->notes['state'] = 'object_type';
               }
               break;
           case 'is_new':
               if ($this->notes['type'] == 'house' || ($this->notes['type'] == 'flat' && $this->notes['is_new'] == 'no')) {
                   $this->notes['state'] = 'object_type';
               } elseif ($this->notes['type'] == 'flat' && $this->notes['is_new'] == 'yes') {
                   $this->notes['state'] = 'rooms';
               }

               break;
           case 'object_type':
               if ($this->notes['type'] == 'flat' || $this->notes['type'] == 'commerce' || $this->notes['type'] == 'house') {
                   $this->notes['state'] = 'rooms';
               } elseif ($this->notes['type'] == 'ground') {
                   $this->notes['state'] = 'square_ground';
               }
               break;
           case 'rooms':
               if ($this->notes['type'] == 'flat' || $this->notes['type'] == 'commerce') {
                   $this->notes['state'] = 'floor';
               } elseif ($this->notes['type'] == 'house') {
                   $this->notes['state'] = 'floors';
               }
               break;
           case 'floor':
               if ($this->notes['type'] == 'flat') {
                   $this->notes['state'] = 'floors';
               } elseif ($this->notes['type'] == 'commerce') {
                   $this->notes['state'] = 'square_full';
               }
               break;
           case 'floors':
               $this->notes['state'] = 'square_full';
               break;
           case 'square_full':
               if ($this->notes['type'] == 'flat' || $this->notes['type'] == 'house') {
                   $this->notes['state'] = 'square_dwelling';
               } elseif ($this->notes['type'] == 'commerce') {
                   $this->notes['state'] = 'condition';
               }
               break;
           case 'square_dwelling':
               $this->notes['state'] = 'square_kitchen';
               break;
           case 'square_kitchen':
               if ($this->notes['type'] == 'flat') {
                   $this->notes['state'] = 'condition';
               } elseif ($this->notes['type'] == 'house') {
                   $this->notes['state'] = 'square_ground';
               }
               break;
           case 'square_ground':
               if ($this->notes['type'] == 'house') {
                   $this->notes['state'] = 'condition';
               } elseif ($this->notes['type'] == 'ground') {
                   $this->notes['state'] = 'description';
               }
               break;
           case 'condition':
               $this->notes['state'] = 'wall_material';
               break;
           case 'wall_material':
               $this->notes['state'] = 'description';
               break;
           case 'description':
               $this->notes['state'] = 'add_description';
               break;
           case 'add_description':
               $this->notes['state'] = 'price';
               break;
           case 'price':
               $this->notes['state'] = 'photos';
               break;
           case 'photos':
               $this->notes['state'] = 'contact_phone';
               break;
           case 'contact_phone':
//                $m_data = $message->getRawData();
               $this->notes['state'] = 'contact_name';
               break;
           case 'contact_name':
               $this->notes['state'] = 'city';
               break;
           case 'city':
               if (isset($this->pages_data['region'][$this->notes['city']])) {
                   $this->notes['state'] = 'region';
               }else{
                   $this->notes['state'] = 'street';
               }
                break;
           case 'region':
               $this->notes['state'] = 'street';
               break;
           case 'street':
               $this->notes['state'] = 'is_private';
               break;
           case 'is_private':
               $this->notes['state'] = 'end';
               break;
           case 'end':
               //               $this->notes['state'] = 'send';
               break;
           case 'send':

               break;
           default:
               $this->conversation->cancel();
               $data['text'] = "Некорректна команда! Зверніться до адмінінстратора.";
               $data['reply_markup'] = Keyboard::remove(['selective' => true]);
               return Request::sendMessage($data);
               break;
       }
       if($this->notes["static_data"] && $this->notes[$this->notes['state']]){
           $this->setNextState($this->notes['state']);
       }
   }

    protected function getReplyMarkup()
    {
        $page = $this->notes['state'];

        if (!$page) {
            return Buttons::getInlineKeyboard(Buttons::getSurveyMenu($this->name, $page, $this->notes[$page]));
        }

        if ($page == 'send') {
            return Buttons::removeKey();
        }

        $no_key = array('square_full', 'square_dwelling', 'square_kitchen', 'description', 'price', 'street', 'contact_phone');

        $inline_keyboard = array();
        switch ($page){
            case 'floor':
            case 'floors':{

                $i = 1;
                $j = 0;
                $keyboard_buttons = array();
                $counts = ['floor' => 41, 'floors' => 41,];
                while ($i < $counts[$page]) {
                    if ($j == 8) {
                        $inline_keyboard[] = $keyboard_buttons;
                        $keyboard_buttons = array();
                        $j = 0;
                    }
                    $keyboard_buttons[] = Buttons::getButton($i, $this->name.';' . $page . ';' . $i ,);
                    $i++;
                    $j++;
                }
                if (!empty($keyboard_buttons)) {
                    $inline_keyboard[] = $keyboard_buttons;
                }

                return Buttons::getInlineKeyboard(array_merge(
                    $inline_keyboard,
                    Buttons::getSurveyMenu($this->name, $page, $this->notes[$page])
                ));
                break;
            }
            case 'object_type':{
                $page =  'object_type/' . $this->notes['type'];
                break;
            }
            case 'region':{
                $page =  'region/' . $this->notes['city'];
                break;
            }
            case 'contact_phone':{
                $open_message = Request::sendMessage([
                    'message_id' => $this->notes['o_message_id'],
                    'chat_id' => $this->chat_id,
                    'text' => mb_convert_encoding('Ви можете вибрати <b>контакт колеги </b>натиснувши на скріпку, далі Контакт.' . PHP_EOL . '<b>Або ж надіслати свій...</b>', "UTF-8"),
                    'parse_mode' => 'HTML',
                    'reply_markup' => Buttons::getKeyboard(Buttons::getPageButtons($this->name, $page))
                ]);

                $this->notes['o_message_id'] = $open_message->isOk() ? $open_message->getResult()->message_id : '';
                break;
            }
            case 'rooms':{
                $page = 'object_rooms';
                break;
            }
        }

//        Messages::sendMeMessage(json_encode(Buttons::getPageButtons($this->name, $page)));
//            Messages::sendMeMessage(json_encode(Buttons::getSurveyMenu($this->name, $page, $this->notes[$page])));
        return Buttons::getInlineKeyboard(array_merge(
            in_array($page, $no_key) ? array() : Buttons::getPageButtons($this->name, $page),
            Buttons::getSurveyMenu($this->name, $page, $this->notes[$page])
        ));
    }


    protected function end($default_data = array())
    {
//            $this->notes["message_id"] = $result->getRawData()["result"]["message_id"];
        $default_data["user_id"] = $this->notes["user_id"] ?? $this->user_id;
        $default_data["published_at"] = $this->notes["published_at"] ?? date('Y-m-d H:i:s');

            $this->notes["object_id"] = parent::end($default_data);

        if($this->notes["data_type"] == 'reamak'){
            $current_reamak_advert = RealtyReamak::findFirst("id = " . $this->notes["data_id"]);
            $current_reamak_advert->status = 'IN_WORK';
            $current_reamak_advert->user_id = $this->user_id;
            $current_reamak_advert->changed_at = date('Y-m-d H:i:s');

            $current_reamak_advert->save();
        };


            // надсилання оголошення на канал
            $chat_id = $this->notes["category"] == 'rent' ? '@estatebookrent' : '@estatebooksale';
            $realty_type = $this->notes['category'] . $this->notes['type'] . 'Tg';
            $inline_keyboard[] = [
                new InlineKeyboardButton(
                    [
                        'text' => 'Отримати фото',
                        'callback_data' => 'getPhotos;inlineKBPhotos;' . ($this->notes['category'] == 'Продаж' ? 's_' : 'r_'),
                    ]
                ),
                new InlineKeyboardButton(
                    [
                        'text' => 'Детальніше',
                        'callback_data' => 'getmore;close;'.$realty_type.';' . $this->notes["id"]
                    ]
                ),
            ];

//            $result = Request::sendMessage(array(
//                'text' => Messages::getObjectPostMessage($this->notes),
//                'chat_id' => $chat_id,
//                'parse_mode' => 'HTML',
//                'reply_markup' => new InlineKeyboard(...$inline_keyboard),
//            ));
//
//            if (!$result->getOk()) {
//                throw new TelegramException("Оголошення не надіслано в групу " . $chat_id . ':' . PHP_EOL . $result->getDescription());
//            }


            // надіслає відправнику повідомлення з інструкціями
            $inline_keyboard_menu[] = [
                new InlineKeyboardButton(
                    [
                        'text' => 'Відправити користувачу',
                        'switch_inline_query' => 'reid_'. $this->notes["object_id"]
                    ]
                ),
            ];

            $inline_keyboard_menu[] = [
                new InlineKeyboardButton(
                    [
                        'text' => 'Додати в обрані',
                        'callback_data' => 'inlinekeyboard;setFavourite;getList;' . $this->notes["object_id"],
                    ]
                ),
                new InlineKeyboardButton(['text' => '❌ ' . TG_CLOSE, 'callback_data' => 'inlinekeyboard;close']),
            ];
//            $result = Request::sendMessage(
//                [
//                    'chat_id' => $this->chat_id,
//                    'text' => 'ID вашого оголошення - ' . $this->notes["object_id"] . PHP_EOL
//                        . 'опубліковано в групі ' . $chat_id . PHP_EOL
//                        . 'Що зробити з оголошенням?',
//                    'reply_markup' => new InlineKeyboard(...$inline_keyboard_menu),
//                ]
//            );
//            if(!$result->isOk()){
//               throw new TelegramException('Final submit message error: '. $result->getDescription());
//            }

            return true;

    }

    protected function getMessageData($notes)
    {
        $card = new ObjectCardBuilder($notes);

        $text = $card->getPreOutMessage();
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