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

use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Modules\TgAdmin\Commands\MyUserCommand;
use Telegram\Featuring\Messages;

/**
 * Start command
 */
class FavouriteCommand extends MyUserCommand
{

    protected function constructor()
    {
        $this->name = 'favourite';
        $this->description = 'Favourite command';
        $this->usage = '/favourite';
        $this->version = '1.1.0';
        $this->model = '\Service\Lists';

        $this->params = array(
            'list' => "",
            'list_name' => "",
            'is_private' => "/Так|Ні/",
            'source' => "",
            'end' => ''
        );

        $this->pages_data = array(
//            "is_private" => [[
//                [
//                    'text' => 'Так',
//                    'callback_data' => 'survey;is_new;1',
//                ],
//                [
//                    'text' => 'Ні',
//                    'callback_data' => 'survey;is_new;0',
//                ],
//            ]],
        );

        $this->page_messages = array(
            'list_name' => '<b>Назва списку?</b>',
            'is_private' => "<b>Список приватний?</b>",
            'source' => "<b>Що містить?</b>",
            'end' => "<b>Перевірте дані</b>",
            'send' => "<b>Список створено</b>",
        );

        $this->optional_list = array(
            'what_can' => true,
            'what_need' => true,
            'url' => true,
        );

        parent::constructor();
    }

    protected function preStart($params = array()){

    }

    protected function setNextState($pre_state)
    {
        switch ($pre_state) {
            case 'start':
            {
                $this->notes['state'] = 'list_name';
                break;
            }
            case 'list_name':
            {
                $this->notes['state'] = 'is_private';
                break;
            }
            case 'is_private':
            {
                $this->notes['state'] = 'end';
                break;
            }
//            case 'source':
//            {
//                $this->notes['state'] = 'end';
//                break;
//            }
            case 'end':
                //               $this->notes['state'] = 'send';
                break;
            case 'send':
                break;
        }
    }

//    protected function setParam()
//    {
//
//        $message_data = $this->message->getRawData();
//        $chat_id = $this->message->getChat()->getId();
//
//        $lastState = $this->getLastPreState($this->notes);
//
//        if (!$lastState) {return false;}
//
//        if (!$this->validParams($lastState, $message_data["text"])) {
//            Request::sendMessage(
//                [
//                    'chat_id' => $chat_id,
//                    'text' => 'Wrong param',
//                ]
//            );
//            return false;
//        };
//
//        $this->notes[$lastState] = $message_data["text"];
//
//        return true;
//    }

    protected function getPreOutMessage()
    {
        $text = Messages::getFavouriteCardMessage($this->notes);
        $text .=  PHP_EOL . PHP_EOL . $this->page_messages[$this->notes['state']];
//        if ($this->notes['state'] == 'end') {
//            $text =  PHP_EOL . PHP_EOL . Messages::getFavouriteCardMessage($this->notes);
//        }
       return $text;
    }

    protected function end($default_data = array())
    {
        $default_data["user_id"] =  $this->user_id;
        $default_data["published_at"] = date('Y-m-d H:i:s');
        $default_data["source"] = 'object';
        return parent::end($default_data);
    }

    private function validate_text($text, $stage = '')
    {
        if (!$text) {
            return false;
        }
        if ($stage = 'phone' && !preg_match('/^0\d{9}$/', $text)) {
            return false;
        }
        return true;
    }

    protected function getReplyMarkup()
    {
        $page = $this->notes['state'];

        if ($page == 'send') {
            return Keyboard::remove();
        }


//            if (isset($this->pages_data[$page])) {
                $inline_keyboard = $this->pages_data[$page] ?? array();
//            }

        $inline_keyboard = array_merge($inline_keyboard, $this->getKeyBoardMenu());

        return (new InlineKeyboard(...$inline_keyboard))->setResizeKeyboard(true);
//        ->setOneTimeKeyboard(true)
//            ->setSelective(true);
    }

}