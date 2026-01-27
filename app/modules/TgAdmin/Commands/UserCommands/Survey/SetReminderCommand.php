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
use Realty\RealtyReamak;
use Modules\TgAdmin\Commands\MyUserCommand;
use Telegram\Featuring\Buttons;
use Telegram\Featuring\Messages;


/**
 * Submit command
 */
//class SubmitCommand extends MyUserCommand
class SetReminderCommand extends MyUserCommand
{

    protected function constructor()
    {
        $this->name = 'setreminder';
        $this->description = 'Set Reminder';
        $this->usage = '/setreminder';
        $this->version = '1.0.0';
        $this->is_button_next = false;
        $this->model = '\Service\Reminders';

        $this->pages_data = array(
            'subject_type' => [[
                [
                    'text' => '🖇 ' . TG_REQUESTS,
                    'callback_data' => $this->name . ';subject_type;request',
                ],
                [
                    'text' => '🖇 ' . TG_OBJECTS,
                    'callback_data' => $this->name . ';subject_type;object',
                ],
                [
                    'text' => '🖇 ' . TG_LEADS,
                    'callback_data' => $this->name . ';subject_type;leads',
                ]],
                [[
                    'text' => '🖇 ' . TG_SHOWINGS,
                    'callback_data' => $this->name . ';subject_type;showings',
                ],
                [
                    'text' => '⭕  Без категорії',
                    'callback_data' => $this->name . ';subject_type;none',
                ],
            ]],
            'period' => [
                [[
                    'text' => '🖇 ' . TG_ONCE,
                    'callback_data' => $this->name . ';period;once',
                ],
                [
                    'text' => '🖇 ' . TG_ONCE_AND_REPORT,
                    'callback_data' => 'inlinekeyboard;infoWindow;inProgress',
                ]],
                [[
                    'text' => '🖇 ' . TG_DAILY,
                    'callback_data' => $this->name . ';period;daily',
                ],
                [
                    'text' => '🖇 ' . TG_DAILY_WITHOUT_SUNDAY,
                    'callback_data' => $this->name . ';period;daily_without_sunday',
                ]]
            ]
        );
        $this->page_messages = array(
            'message' => "<b>Текст повідомлення:</b>",
            'receiver' => "<b>Отримувач:</b>",
            'reminder_at' => "<b>День нагадування:</b>",
            'reminder_at_time' => "<b>Час нагадування:</b>",
            'period' => "<b>Період:</b>",
            'subject_type' => "<b>Категорія:</b>",
            'subject_id' => "<b>Елемент:</b>",
            'end' => '<b>Превірте своє оголошення</b>',
        );
        $this->optional_list = array();
        parent::constructor();
    }

    protected function preStart($params = array()){
        $this->notes["subject_type"] = $this->notes["data_type"];
        $this->notes["subject_id"] = $this->notes["data_id"];
    }

    protected function setNextState($pre_state)
   {
       switch ($pre_state) {
           case 'start':
           {
               $this->notes['sender_id'] = $this->user_id;
               $this->notes['_subject_type'] = $this->notes["data_type"] ?? '';
               $employee_admin = Employees::findFirst("user_id = " . $this->user_id . " AND position = 'admin'");

               if ($employee_admin) {
                   $this->notes['company_id'] = $employee_admin->company_id;
                   $this->notes['state'] = 'receiver';
               } else {
                   $this->notes['receiver'] = $this->user_id;
                   $this->notes['state'] = 'message';
               }
               break;
           }
           case 'receiver':{
               $this->notes['state'] = 'message';
               break;
           }
           case 'message':
//               Messages::sendMeMessage($this->notes['subject_type']);
               if($this->notes['_subject_type']){
                   $this->notes['state'] = 'period';
               }else {
                   $this->notes['state'] = 'subject_type';
               }
               break;
           case 'subject_type':
               if($this->notes['subject_type'] == 'none'){
                   $this->notes['state'] = 'period';
               }else{
                   $this->notes['state'] = 'subject_id';
               };
               break;
           case 'subject_id':
               $this->notes['state'] = 'period';
               break;
           case 'period':
               $this->notes['state'] = 'reminder_at';
               break;
           case 'reminder_at':
               $this->notes['state'] = 'reminder_at_time';
               break;
           case 'reminder_at_time':
               $this->notes['state'] = 'end';
               break;
           case 'end':
//               $this->end();
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

        $inline_keyboard = array();

        switch ($page){
            case 'receiver':{
                $buttons = array();
                $employees = Employees::find([
                    'company_id = :c_id:',
                    'bind' => [
                        'c_id' => $this->notes['company_id'],
                    ],
                ]);

                $employees_position = Employees::count([
                    'company_id = :c_id:',
                    'bind' => [
                        'c_id' => $this->notes['company_id'],
                    ],
                    'group' => 'position'
                ]);
                foreach ($employees_position as $employee_position){
                    $buttons[] = Buttons::getButton(
                        '👥 ' . constant('TG_' . $employee_position->position) . ' ('. $employee_position->rowcount .')',
                        'setreminder;receiver;' . $employee_position->position
                    );
                }
                foreach ($employees as $employee){
                    $buttons[] = Buttons::getButton(
                        '👤 ' . Messages::getTittle('user', $employee->user_id),
                        'setreminder;receiver;' . $employee->user_id
                    );
                }
                $inline_keyboard_line = array();
                foreach ($buttons as $key => $button){
                    $inline_keyboard_line[] = $button;

                    if (($key % 2)){
                        $inline_keyboard[] = $inline_keyboard_line;
                        $inline_keyboard_line = array();
                    }
                }
                if($inline_keyboard_line){
                    $inline_keyboard[] = $inline_keyboard_line;
                }
                break;
            }
            case "reminder_at":{

                $month =  $this->notes['show_date'] ? explode('-', $this->notes['show_date'])[2] : date('m');
                $year =  $this->notes['show_date'] ? explode('-', $this->notes['show_date'])[3] : date('Y');

                $inline_keyboard = Buttons::getCalendar($month, $year, $this->name, $page);
                break;
            }
            case 'reminder_at_time':
            {
                $inline_keyboard_line = array();
                for($i = 7; $i < 23; $i++) {
                    for ($j = 0; $j < 60; $j += 15) {
                        $time = sprintf("%02d", $i) . ':' . sprintf("%02d", $j);
                        $inline_keyboard_line[] = Buttons::getButton($time, $this->name . ';' . $page . ';' . $time);
                    }
                    $inline_keyboard[] = $inline_keyboard_line;
                    $inline_keyboard_line = array();
                }
                break;
            }
            default :{
                $inline_keyboard = $this->pages_data[$page] ?? array();
            }
        };

//        $lastState = $this->getLastPreState($this->notes);

        $inline_keyboard = array_merge($inline_keyboard, $this->getKeyBoardMenu());

        return (new InlineKeyboard(...$inline_keyboard))->setResizeKeyboard(true);
    }

    protected function getPreOutMessage()
    {
        $text = Messages::getReminderCardMessage($this->notes) . PHP_EOL;
        $text .= '-------------------------------------' . PHP_EOL;
        return mb_convert_encoding( $text . $this->page_messages[$this->notes['state']] , "UTF-8");
    }

    protected function end($default_data = array())
    {

        $default_data["subject_type"] = $this->notes["_subject_type"] ?? $this->notes["subject_type"];
        $default_data["status"] = $this->notes["status"] ?? "active";
        $default_data["created_at"] = $this->notes["created_at"] ?? date('Y-m-d H:i:s');
        $default_data["reminder_at_time"] = date('Y-m-d H:i:s');

//        $reminde_time = new \DateTime($year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $minute . ':00');
//        $default_data["reminde_time"] = $this->notes["reminde_time"] ?? $reminde_time->format('Y-m-d H:i:s');

        $reminder_id = parent::end($default_data);

        if( $this->notes["subject_type"] == 'reamak') {
            $owner_object = RealtyReamak::findFirst('id = ' . $this->notes["subject_id"]);
            $owner_object->status = 'reminder';
            $owner_object->status_data = $this->notes["subject_id"];
            $owner_object->user_id = $this->user_id;
            $owner_object->changed_at = date('Y-m-d H:i:s');

            $owner_object->save();
        }

//        $this->exitMe();
    }

}