<?php

namespace Modules\TgAdmin\Commands\UserCommands;

use Bot\Exception\BotException;
use Bot\Exception\TelegramApiException;
use Longman\TelegramBot\Commands\Command;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\KeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Person\ColdPhones;
use Person\Contacts;
use Modules\TgAdmin\Commands\MyUserCommand;
use Modules\TgAdmin\Featuring\Cards;
use Modules\TgAdmin\Featuring\Messages;
use Modules\TgAdmin\Featuring\Buttons;


/**
 * Submit command
 */
//class SubmitCommand extends MyUserCommand
class CallCommand extends MyUserCommand
{

    protected function constructor()
    {
        $this->name = 'call';
        $this->description = 'Call to leads';
        $this->usage = '/call';
        $this->version = '1.0.0';
        $this->is_button_next = false;
        $this->model = '\EB_DB\ColdPhones';

        $this->pages_data = array(
            'status' => [[
                [
                    'text' => TG_CALLED,
                    'callback_data' => 'call;status;called',
                ],
                [
                    'text' => TG_NOT_ACTUAL,
                    'callback_data' => 'call;status;not_actual',
                ]],[
                [
                    'text' => TG_NOT_CALLED,
                    'callback_data' => 'call;status;not_called',
                ],[
                    'text' => 'Ріелтор',
                    'callback_data' => 'call;status;realtor',
                ]
            ]],
        );
        $this->page_messages = array(
            'name' => "<b>Ім'я: </b>",
            'description' => "<b>Опис результату дзвінка:</b>",
            'status' => "<b>Статус:</b>",
            'end' => '<b>Збереження</b>',
        );
        $this->optional_list = array('name' => true, 'description' => true);
        parent::constructor();
    }

    protected function preStart($params = array()){

    }

    protected function setNextState($pre_state)
   {
       switch ($pre_state) {
           case 'start':
               $this->notes['state'] = 'status';
               break;
           case 'status':
               $this->notes['state'] = 'name';
               if ($this->notes['status'] == 'not_called'){
                   $this->notes['state'] = 'end';
               }elseif ($this->notes['status'] == 'not_actual'){
                   $this->notes['state'] = 'description';
               }
               break;
           case 'name':
               $this->notes['state'] = 'description';
               break;
           case 'description':
               $this->notes['state'] = 'end';
               break;
           case 'end':
               $this->end();
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
   }

    protected function getReplyMarkup()
    {
        $page = $this->notes['state'];

        if ($page == 'send') {
            return Keyboard::remove();
        }

        $inline_keyboard = $this->pages_data[$page] ?? array();

//        $lastState = $this->getLastPreState($this->notes);

        $inline_keyboard = array_merge($inline_keyboard, $this->getKeyBoardMenu());

        return (new InlineKeyboard(...$inline_keyboard))->setResizeKeyboard(true);
    }

    protected function getPreOutMessage()
    {
        $message = $this->notes['name'] ? '<b>Ім\'я</b>: ' . $this->notes['name'] . PHP_EOL : '';
        $message .= $this->notes['description'] ? '<b>Опис</b>: ' . $this->notes['description'] . PHP_EOL : '';
        $message .= $this->notes['status'] ? '<b>Статус</b>: ' . (constant('TG_'.$this->notes['status']) ?? $this->notes['status']) . PHP_EOL : '';
        $message .= '-------------------' . PHP_EOL;
        return mb_convert_encoding($message . $this->page_messages[$this->notes['state']] , "UTF-8");
    }

    protected function end($default_data = array())
    {

        switch ($this->notes['status']){
            case 'waiting':{
                Messages::sendCallbackAnswer($this->callback_query->getId(), 'Потрібно обрати статус', true);
                exit();
            }
            case 'realtor':{
                $contact = new Contacts();
                $contact->setContact([
                    'name' => $this->notes["name"],
                    'phone' => $this->notes["phone"],
                    'description' => 'З заявок по рекламі:  ' . $this->notes["description"],
                    'category' => $this->notes['status'],
                ]);
                if (!$contact->save()) {
                    $mess = '';
                    foreach ($contact->getMessages() as $msg) {
                        $mess .= $msg . ', ';
                    }
                    Messages::sendCallbackAnswer($this->callback_query->getId(), 'Не вдалось зберегти ріелтора в базу', true);
                }
                break;
            }
        }
        $lead = ColdPhones::findFirst("id = '" . $this->notes["id"] . "'");

        $lead->name = $this->notes["name"];
        $lead->description = $this->notes["description"] . PHP_EOL . $lead->description;
        $lead->status = $this->notes["status"];
        $lead->contacted_at = $this->notes["contacted_at"] ?? date('Y-m-d H:i:s');

        if (!$lead->save()) {
            $mess = '';
            foreach ($lead->getMessages() as $msg) {
                $mess .= $msg . ', ';
            }
            throw new TelegramException('Не вдалося зберегти дані в базу (' . $this->usage . ')' . PHP_EOL . $mess);
        }
//        exit;
    }

    private function checkNumber($numb){
        $main_model = $this->model::findFirst("phone = '" . $numb . "'");
        if (!$main_model) return false;
        return true;
        return Cards::coldPhoneCard($main_model);
    }
}