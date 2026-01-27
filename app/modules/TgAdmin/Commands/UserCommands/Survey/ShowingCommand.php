<?php

namespace Modules\TgAdmin\Commands\UserCommands;

use Bot\Exception\BotException;
use Bot\Exception\TelegramApiException;
use Company\Employees;
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
use Person\AppUsers;
use Service\Reminders;
use Modules\TgAdmin\Commands\MyUserCommand;
use Telegram\Featuring\Buttons;
use Telegram\Featuring\Messages;

/**
 * Showing command
 */
//class SubmitCommand extends MyUserCommand
class ShowingCommand extends MyUserCommand
{

    protected function constructor()
    {
        $this->name = 'showing';
        $this->description = 'Showing command';
        $this->usage = '/showing';
        $this->version = '1.0.0';
        $this->is_button_next = false;
        $this->model = '\Requests\TelegramEbShowing';

        $this->pages_data = array(
            "is_cooperation" => [[
                [
                    'text' => TG_YES,
                    'callback_data' => $this->name . ';is_cooperation;1',
                ],
                [
                    'text' => TG_NO,
                    'callback_data' => $this->name . ';is_cooperation;0',
                ],
            ]],
            "client_type" => [[
                [
                    'text' => 'Орендаря',
                    'callback_data' => $this->name . ';client_type;renter',
                ],
                [
                    'text' => 'Власника',
                    'callback_data' => $this->name . ';client_type;owner',
                ],
            ]],
            "result" => [[
                [
                    'text' => TG_AGREEMENT,
                    'callback_data' => $this->name . ';result;agreement',
                ],
                [
                    'text' => TG_DEPOSIT,
                    'callback_data' => $this->name . ';result;deposit',
                ],],[
                [
                    'text' => TG_CONSIDING,
                    'callback_data' => $this->name . ';result;considing',
                ],
                [
                    'text' => TG_REFUSAL,
                    'callback_data' => $this->name . ';result;refusal',
                ],
                [
                    'text' => TG_CANCELED,
                    'callback_data' => $this->name . ';result;canceled',
                ],
            ]],
        );
        $this->page_messages = array(
            'is_cooperation' => "<b>Показ по співпраці?</b>",
            'client_type' => "<b>Показ від власника чи орендаря (покупця)?</b>",
            'object' => "<b>Оберіть об'єкт:</b>",
            'request_id' => "<b>Оберіть заявку:</b>",
            'accountable_id' => "<b>Оберіть відповідального:</b>",
            'status' => "<b>Статус:</b>",
            'description' => '<b>Опис:</b> (попередній перегляд до 300 символів)',
            'showing_at' => '<b>Дата показу:</b>',
            'showing_at_time' => '<b>Час показу: </b>(hh.mm)',
            'result' => '<b>Результат показу: </b>',
            'end' => '<b>Превірте дані показу</b>',
            'send' => '<b>Показ успішно створено</b>',
        );

        parent::constructor();
    }

    protected function preStart($params = array()){

    }

    protected function setNextState($pre_state)
   {
       switch ($pre_state) {
           case 'start':
               $this->notes['state'] = "is_cooperation";
               break;
           case 'complete':
               $this->notes['state'] = "result";
               $this->notes["status"] = 'completed';
               break;
           case 'is_cooperation':
//               if ($this->notes['is_cooperation'] == true) {
                   $this->notes['state'] = 'client_type';
//               } else{
//                   $this->notes['state'] = 'request_id';
//               }
               break;
           case 'client_type':
               $this->notes['state'] = "request_id";
               break;
           case 'request_id':
               $this->notes['state'] = 'object_id';
               break;
           case 'object_id':
               $this->notes['state'] = 'accountable_id';
               break;
           case 'postpone':
               $this->notes["status"] = 'postponed';
           case 'accountable_id':
               $this->notes['state'] = 'showing_at';
               break;
           case 'showing_at':
               $this->notes['state'] = 'showing_at_time';
               break;
           case 'result':
           case 'showing_at_time':
               $this->notes['state'] = 'description';
               break;
           case 'description':
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
   }

//    protected function doCommand($command)
//    {
//        switch ($command) {
//
//            default:{
//                return parent::doCommand($command);
//            }
//        }
//        return true;
//    }

    protected function getReplyMarkup()
    {
        $page = $this->notes['state'];

        if ($page == 'send') {
            return Keyboard::remove();
        }

        if (in_array($page, array('description',))) {
            $page = null;
        }

        $inline_keyboard = array();
        if ($page) {
            switch ($page){
                case 'request_id':{
                    $requests = TelegramEbRequest::find("accountable_id='" . $this->user_id . "'");
                    foreach ($requests as $request) {
                        $inline_keyboard[] = array( new InlineKeyboardButton([
                            'text' => $request->search_name,
                            'callback_data' => $this->name . ';' . $page . ';' . $request->id,
                        ]));
                    }
                    break;
                }
                case 'object_id':{
                    $objects = RealtyTg::find("user_id='" . $this->user_id . "'");

                    foreach ($objects as $object) {
                        $inline_keyboard[] = array( Buttons::getButton(
                            Messages::getTittle('object', $object->id),
                            $this->name . ';' . $page . ';' . $object->id
                        ));
                    }
                    break;
                }
                case 'accountable_id':{
                    $employees = Employees::getMyEmployees($this->user_id);
                    if($employees){

                        foreach ($employees as $employee) {
                            $current_user = AppUsers::findFirst("id='" . $employee->user_id . "'");
                            $inline_keyboard[] = array( Buttons::getButton(
                                $current_user->first_name . ' ' . $current_user->last_name,
                                $this->name . ';' . $page . ';' . $current_user->id
                            ));
                        }
                    }else {
                        $me = AppUsers::findFirst("id='" . $this->user_id . "'");
                        $inline_keyboard[] = array( Buttons::getButton(
                            $me->first_name . ' ' . $me->last_name,
                            $this->name . ';' . $page . ';' . $me->id
                        ));
                    }
                    break;
                }
                case 'showing_at':{
                    $month =  $this->notes['show_date'] ? explode('-', $this->notes['show_date'])[2] : date('m');
                    $year =  $this->notes['show_date'] ? explode('-', $this->notes['show_date'])[3] : date('Y');

                    $inline_keyboard = Buttons::getCalendar((int) $month, (int) $year, $this->name, $page);
                    break;
                }

                default :{
                    $inline_keyboard = $this->pages_data[$page] ?? array();
                }
            };
        }

//        $lastState = $this->getLastPreState($this->notes);

        $inline_keyboard = array_merge($inline_keyboard, $this->getKeyBoardMenu());

        return (new InlineKeyboard(...$inline_keyboard))->setResizeKeyboard(true);
    }

    protected function getPreOutMessage()
    {

        $text = Messages::getShowingCardMessage($this->notes);

//        $text .= $this->notes['is_private'] ? PHP_EOL . '<b>Приватний:</b> ' . constant('TG_' . strtoupper($this->notes['is_private'])) : ' ';
        $text .= PHP_EOL . PHP_EOL . '-------------------' . PHP_EOL;

        return mb_convert_encoding($text . $this->page_messages[$this->notes['state']], "UTF-8");
    }

    protected function end($default_data = array())
    {
        $now = new \DateTime();

        $default_data["author_id"] = $this->user_id;
        $default_data["created_at"] = $now->format('Y-m-d H:i:s');
        $default_data["status"] = 'waiting';
//        $default_data["description"] = 'Показ створено.';

        $showing_id = parent::end($default_data);

        $reminder = new Reminders();
        $reminder->setDataFromConversation([
            "sender_id" => $this->user_id,
            "receiver" => $this->notes["accountable_id"],
            "message" => "За годину показ",
            "status" => "active",
            "period" => "once",
            "subject_id" => $showing_id,
            "subject_type" => "showing",
            "created_at" => $now->format('Y-m-d H:i:s'),
            "reminder_at" => $now->sub(new \DateInterval("P1H"))->format('Y-m-d H:i:s')
        ]);
        if (!$reminder->save()) {
            $mess = '';
            foreach ($reminder->getMessages() as $msg) {
                $mess .= $msg . ', ';
            }
            Messages::sendMessage($this->user_id, $mess);
        }
        return $showing_id;
    }

}