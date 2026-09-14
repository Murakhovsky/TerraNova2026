<?php


namespace Interfaces\Telegram\Presentation;


use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Request;

class Cards
{
    public $id;
    public $data;

    public function __construct($data, $id)
    {
        $this->data = $data;
        $this->id = $id;
    }


    static public function showCard($card_message, $command, $callback_id){

        if($command == 'show_card' || $command == 'show') {
            $request = Request::sendMessage($card_message);
            $success_message = 'Повідомлення надіслано';
        } else {
            $request = Request::editMessageText($card_message);
            $success_message = 'Повідомлення оновлено';
        }

        if($request->isOk()) {
            Messages::sendCallbackAnswer($callback_id, $success_message);
        }else{
            Messages::sendCallbackAnswer($callback_id, $request->getDescription(), true);
        }

        return $request;
    }


}
