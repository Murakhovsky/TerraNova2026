<?php

namespace Modules\TgAdmin\Renderer;

use http\Params;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use Modules\TgAdmin\Featuring\Buttons;
use TelegramModels\Advert;
use TelegramModels\Adverts\RealtyTg;
use TelegramModels\TelegramBaseModel;
use TelegramModels\TelegramEbCompany;
use TelegramModels\TelegramEbEmployees;
use Longman\TelegramBot\Entities\InlineQuery\InlineQueryResultArticle;

class Messages
{

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

    public static function sendMeMessage(string $text): ServerResponse{
        return self::sendMessage(522625209, $text);
    }

    public static function sendMessage(int $user_id, string $text, Keyboard|array|null $reply_markup = array()): ServerResponse{
        $data = [
            'chat_id' => $user_id,
            'text' =>  $text,
            'parse_mode' => 'HTML'
        ];
        if($reply_markup){$data["reply_markup"] = $reply_markup;}
        $request = Request::sendMessage($data);

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