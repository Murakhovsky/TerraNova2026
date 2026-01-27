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
use Modules\TgAdmin\Commands\MyUserCommand;
use Telegram\Featuring\Buttons;
use Telegram\Featuring\Messages;


/**
 * Submit command
 */
//class SubmitCommand extends MyUserCommand
class SetColdPhonesCommand extends MyUserCommand
{

    protected function constructor()
    {
        $this->name = 'setcoldphones';
        $this->description = 'Set Cold Phones';
        $this->usage = '/setcoldphones';
        $this->version = '1.0.0';
        $this->is_button_next = false;
        $this->model = '\EB_DB\ColdPhones';

        $this->pages_data = array(
//            'set_phones' => [[
//                [
//                    'text' => '1к',
//                    'callback_data' => 'submit;rooms;1',
//                ],
//                [
//                    'text' => '2к',
//                    'callback_data' => 'submit;rooms;2',
//                ],
//                [
//                    'text' => '3к',
//                    'callback_data' => 'submit;rooms;3',
//                ],
//                [
//                    'text' => '4к',
//                    'callback_data' => 'submit;rooms;4',
//                ],
//                [
//                    'text' => '5к+',
//                    'callback_data' => 'submit;rooms;5',
//                ]
//            ]],
        );
        $this->page_messages = array(
            'set_phones' => "<b>Введіть номера через кому починаючи з нуля:</b>",
            'end' => '<b>Превірте своє оголошення</b>',
        );
        $this->optional_list = array();
        parent::constructor();
    }

    protected function preStart($params = array()){

    }

    protected function setNextState($pre_state)
   {
       switch ($pre_state) {
           case 'start':
               $this->notes['state'] = 'set_phones';
               break;
           case 'set_phones':
//               $this->notes['state'] = 'end';
               $this->end();
               break;
           case 'end':
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
//        if ($page) {
//            switch ($page){
//                case 'set_phones':{
//                }
//                default :{
//                    $inline_keyboard = $this->pages_data[$page] ?? array();
//                }
//            };
//        }

//        $lastState = $this->getLastPreState($this->notes);

        $inline_keyboard = array_merge($inline_keyboard, $this->getKeyBoardMenu());

        return (new InlineKeyboard(...$inline_keyboard))->setResizeKeyboard(true);
    }

    protected function getPreOutMessage()
    {
        return mb_convert_encoding( $this->page_messages[$this->notes['state']] , "UTF-8");
    }

    protected function end($default_data = array())
    {
        $bed_phones = '';
        $bed_phones_count = 0;
        $phones_in_base = '';
        $phones_in_base_count = 0;
        $cant_save = '';
        $cant_save_count = 0;

        $phones = explode(',', $this->notes['set_phones']);
        $employee = Employees::findFirst('user_id = "' . $this->user_id . '"');
        if(!$employee) {
            Messages::sendCallbackAnswer($this->callback_query->getId(), 'Додати номера може лише працівник компанії', true);
            exit();
        }


        foreach ($phones as $phone) {
            if (!$this->validParams('phone', trim($phone))) {
                $bed_phones .= $phone . ', ';
                $bed_phones_count++;
                continue;
            };

            if ($message = $this->checkNumber(trim($phone))) {
                $phones_in_base .= $message . PHP_EOL;
                $phones_in_base_count++;
                continue;
            }
            $main_model = new $this->model();
            $main_model->phone = trim($phone);
            $main_model->author_id = $this->chat_id;
            $main_model->company_id = $employee->company_id;
            $main_model->status = 'new';
            $main_model->added_at = date('Y-m-d H:i:s');

            if (!$main_model->save()) {
                $mess = '';
                foreach ($main_model->getMessages() as $msg) {
                    $mess .= $msg . ', ';
                }
                $cant_save .= trim($phone) . ', ';
                $cant_save_count++;
//                    throw new TelegramException('Не вдалося зберегти дані в базу (' . $this->usage . ')' . PHP_EOL . $mess);
            }
        }


        $saved_count = count($phones) - $bed_phones_count - $phones_in_base_count - $cant_save_count;
        $info_text = '<b>Збережено ' . $saved_count . ' номерів з ' . count($phones)  . ' введених.</b>' . PHP_EOL . PHP_EOL
            . ($bed_phones_count ? 'Номера, які було введено не правильно: ('  .  $bed_phones_count . 'шт)' . PHP_EOL . PHP_EOL . $bed_phones . PHP_EOL . PHP_EOL : '')
            . ($phones_in_base_count ? 'Номера, які вже є в базі: (' . $phones_in_base_count . 'шт)' . PHP_EOL . PHP_EOL . $phones_in_base : '');

        Request::sendMessage([
            'chat_id' => $this->chat_id,
            'parse_mode' => 'HTML',
            "reply_markup" => new InlineKeyboard(... array(array(Buttons::getButton('❌ ' . TG_CLOSE, 'inlinekeyboard;close;')))),
            'text' => 'ℹ️' . $info_text
        ]);
//        Messages::sendInfoMessage($this->chat_id, $info_text, 3);

        $this->exitMe();
        exit;
    }

    private function checkNumber($numb){
        $cold_phone = $this->model::findFirst("phone = '" . $numb . "'");
        if (!$cold_phone) return false;

        $message = '<b>' . $cold_phone->phone . '</b> в базі з ' . substr($cold_phone->added_at, 0, 10) . ', зі статусом <b>' . constant('TG_' . $cold_phone->status) . '</b>';
        switch ($cold_phone->status){
            case 'waiting':{
                $old_status = $cold_phone->status;
                $cold_phone->status = 'new';
            }
            case 'not_actual':
            case 'not_called':
            case 'called': // чи  має перерозприділятися лід без заявки на наступний день???
            case 'in_work':
            case 'realtor':{
                $date = $cold_phone->contacted_at ?? $cold_phone->added_at;
                $cold_phone->description = PHP_EOL . "Звернувася повторно. В статусі '"
                    . (isset($old_status) ? constant('TG_' . $old_status) : constant('TG_' . $cold_phone->status))
                    . "' з " . substr($date, 0, 10)
                    . PHP_EOL . $cold_phone->description;

                $cold_phone->contacted_at = null;
                $cold_phone->added_at = date('Y-m-d H:i:s');
                break;
            }
            case 'new':break;
            default:{
                $message = $cold_phone->phone . ' невідомий статус ліда.';
            }
        }
        $cold_phone->save();
        return $message;
//        return Cards::coldPhoneCard($cold_phone);
    }
}