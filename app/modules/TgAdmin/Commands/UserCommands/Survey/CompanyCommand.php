<?php

namespace Modules\TgAdmin\Commands\UserCommands;

use Company\Companies;
use Company\Employees;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\KeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Request;
use Person\AppUsers;
use Modules\TgAdmin\Commands\MyUserCommand;
use Telegram\Featuring\Messages;

/**
 * Company command
 */

class CompanyCommand extends MyUserCommand
{

    protected function constructor()
    {
        $this->name = 'company';
        $this->description = 'Company command';
        $this->usage = '/company';
        $this->version = '1.0.0';
        $this->is_button_next = false;
        $this->model = '\Company\Companies';

        $this->pages_data = array(
//            "is_cooperation" => [[
//                [
//                    'text' => 'Так',
//                    'callback_data' => $this->name . ';is_cooperation;yes',
//                ],
//                [
//                    'text' => 'Ні',
//                    'callback_data' => $this->name . ';is_cooperation;no',
//                ],
//            ]],
        );
        $this->page_messages = array(
            'name' => "<b>Назва компанії / АН?</b>",
            'description' => '<b>Опис:</b> (попередній перегляд до 300 символів)',
            'url' => '<b>Вставте посилання на свій сайт</b>, ' . PHP_EOL . 'чи інше онлайн місце де ви представлені:',
            'end' => '<b>Превірте дані</b>',
            'send' => '<b>Компанію успішно створено</b>',
        );
        $this->optional_list = array(
            'square_dwelling' => true,
            'square_kitchen' => true,
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
               $user = AppUsers::findFirst("id='" . $this->user_id . "'");
               if($user){
                   $this->notes['admin'] = $user->id;
                   $this->notes['admin_name'] = $user->first_name . ' ' . $user->last_name;
               }
               $this->notes['state'] = "name";
               break;
           case 'name':
               $this->notes['state'] = 'description';
               break;
           case 'description':
               $this->notes['state'] = 'url';
               break;
           case 'url':
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

    protected function getReplyMarkup()
    {
        $page = $this->notes['state'];

        if ($page == 'send') {
            return Keyboard::remove();
        }

        if (in_array($page, array('name', 'description',))) {
            $page = null;
        }

        $inline_keyboard = array();
        if ($page) {
            switch ($page){
                case 'example':{
                    $requests = TelegramEbRequest::find("user_id='" . $this->user_id . "'");

                    foreach ($requests as $request) {
                        $inline_keyboard[] = array( new InlineKeyboardButton([
                            'text' => $request->name,
                            'callback_data' => $this->name . ';' . $page . ';' . $request->id,
                        ]));
                    }
                    break;
                }
                default :{
                    $inline_keyboard = $this->pages_data[$page] ?? array();
                }
            };
        }

        $inline_keyboard = array_merge($inline_keyboard, $this->getKeyBoardMenu());

        return (new InlineKeyboard(...$inline_keyboard))->setResizeKeyboard(true);
    }

    protected function getPreOutMessage()
    {
        $text = Messages::getCompanyCardMessage($this->notes);
        $text .= PHP_EOL . PHP_EOL . '-------------------' . PHP_EOL;
        $text .= $this->page_messages[$this->notes['state']];
//        $currency = $this->notes['state'] == 'price' ? ' (' . $this->notes['currency'] . ')' : '';
        return mb_convert_encoding($text , "UTF-8");
    }

    protected function end($default_data = array())
    {
        $default_data["employees"] = json_encode((string)$this->notes["admin_id"]);
        $default_data["created_at"] = date('Y-m-d H:i:s');
        $default_data["status"] = 'registered';
        $default_data["admin_id"] = $this->user_id;

        // зберігає в базу даних, повертає ID
        $company_id = parent::end($default_data);


        $owner = AppUsers::findFirst("id='".$this->user_id."'");

        $owner->company_id = (int)$company_id;
        if(!$owner->save()) {};

        $employee = new Employees();
        $employee->setDataFromConversation([
            "user_id" => $this->user_id,
            "company_id"=> (int)$company_id,
            "position" => "owner",
            "status" => "active"
        ]);

        if(!$employee->save()){
//            $mess = '';
//            foreach ($employee->getMessages() as $msg) {
//                $mess .= $msg;
//            }
//            Messages::sendMeMessage($mess);
        };
    }

    protected function getConversationData($id){
        return Companies::getDataArray($this->user_id);
    }
}