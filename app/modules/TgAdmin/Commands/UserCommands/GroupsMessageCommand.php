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

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Message;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;
use Telegram\Featuring\Messages;

/**
 * Start command
 */
class GroupsMessageCommand extends UserCommand
{
    /**
     * @var string
     */
    protected $name = 'groupsmessage';

    /**
     * @var string
     */
    protected $description = 'GroupsMessage command';

    /**
     * @var string
     */
    protected $usage = '/groupsmessage';

    /**
     * @var string
     */
    protected $version = '1.0.0';


    public function execute(): ServerResponse
    {
        $this->message = $this->getMessage();
        $chat = $this->message->getChat();
        $text = $this->message->getText();
        $bot_id = $this->getTelegram()->getBotId();

        $message_id = $this->message->getMessageId();
        $chat_id = $chat->getId();
        $new_members = $this->message->getNewChatMembers();
        $left_members = $this->message->getLeftChatMember();
        $user = $this->message->getFrom();


        if($key_word = $this->isContainBanWord($text)){
            $request = Request::deleteMessage(
                [
                    'chat_id' => $chat_id,
                    'message_id' => $message_id
                ]
            );

            Request::banChatMember(array(
                "chat_id" => $chat_id,
                "user_id" => $user->getId()
            ));

            //replace message in group

            $report_text_in_chat = "Повідомлення від " .
                    ($user->getUsername() ? '@' . $user->getUsername() : '<b>' . $user->getFirstName() . ' ' . $user->getLastName() . '</b>') .
                    ' автоматично видалено через <a href="https://telegra.ph/Tittle-12-16">підозрілий вміст</a>.';
//                        PHP_EOL . PHP_EOL . 'Для додаткової інформації зверніться до адміністратора.';

//                    Request::sendMessage([
//                        'text' => $report_text_in_chat,
//                        'chat_id' => $chat_id,
//                        'parse_mode' => 'HTML',
//                        'disable_web_page_preview' => true
//                    ]);

            // send report to Spam chanel
            $report_text = "<b>Sender</b>: " . $user->getFirstName() . ' ' . $user->getLastName() . PHP_EOL .
                ($user->getUsername() ? "<b>Username</b>: " . $user->getUsername() . PHP_EOL : '') .
                "<b>ID</b>: " . $user->getId() . PHP_EOL .
                "<b>Message</b>: " . $text . PHP_EOL .
                "<b>Word</b>: " . $key_word;

            Messages::sendMessage(-1001702876091, $report_text);
            return $request;
        }
//
//        if(count($new_members)) {
//            $data = array();
//            foreach ($new_members as $new_member) {
//                $data['user_id'] = $new_member->getId();
//                $data['is_bot'] = $new_member->getIsBot();
//                $data['first_name'] = $new_member->getFirstName();
//                $data['last_name'] = $new_member->getLastName();
//                $data['username'] = $new_member->getUsername();
//                $data['language'] = $new_member->getLanguageCode();
//                $data['property'] = $new_member->getProperty();
//
//            }
//        }

        if($parsed_data = $this->parseMessage($text)){

        }

        return true;
    }

    private function isContainBanWord($message){
        $key_words = array("bot", "виплат", "компенс", "фінансов", "допомог", "акція", "ООН", "Райф", "ПУМБ", "картк", "НАТО", "отрим", "робота", "вимог");
//            $spam_flag = false;
        foreach($key_words as $key_word) {
             if (mb_stripos($message, $key_word) !== false){
                 return $key_word;
             };
        }
        return false;
    }

    private function parseMessage($message){
        $semantic_core = array(
            "rooms" => array(

            ),
            "price" => function($message){

            }
        );
    }
}