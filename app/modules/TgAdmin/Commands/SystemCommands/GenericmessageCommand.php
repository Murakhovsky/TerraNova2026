<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;
use OpenAI\ChatGPT;
use Modules\TgAdmin\Featuring\Messages;

/**
 * Generic message command
 */
class GenericmessageCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = Telegram::GENERIC_MESSAGE_COMMAND;

    /**
     * @var string
     */
    protected $description = 'Handle generic message';

    /**
     * @var string
     */
    protected $version = '1.2.0';

    /**
     * @var bool
     */
    protected $need_mysql = true;

    protected $message;
    /**
     * Execution if MySQL is required but not available
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function executeNoDb(): ServerResponse
    {
        // Try to execute any deprecated system commands.
        if (self::$execute_deprecated && $deprecated_system_command_response = $this->executeDeprecatedSystemCommand()) {
            return $deprecated_system_command_response;
        }

        return Request::emptyResponse();
    }

    /**
     * Execute command
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $this->message = $this->getMessage();
        $chat = $this->message->getChat();
        $bot_id = $this->getTelegram()->getBotId();

        $message_id = $this->message->getMessageId();
        $message_text = $this->message->getText(true);
        $chat_id = $chat->getId();
        $new_members = $this->message->getNewChatMembers();
        $left_members = $this->message->getLeftChatMember();
        $user_id = $this->message->getFrom()->getId();
        $groups = array(
            -1001638696026, // @rentlvivadverts  Оренда Львів. Оголошення.
            -1001536761422, // @salelvivadverts  Продаж Львів. Оголошення.
            -1001662246088, // @estatebookkyivsale EstateBook Київ Продаж
            -1001155920854, // @estatebookgroup EstateBook CLUB
            -1001415124921
        );

        if(in_array($chat_id, $groups) && (count($new_members) || count($left_members))) {
            Request::deleteMessage(
                [
                    'chat_id' => $chat_id,
                    'message_id' => $message_id
                ]
            );
            $data = array();
            if(count($new_members)){

                foreach ($new_members as $new_member){
                    $data['user_id'] = $new_member->getId();
                    $data['is_bot'] = $new_member->getIsBot();
                    $data['first_name'] = $new_member->getFirstName();
                    $data['last_name'] = $new_member->getLastName();
                    $data['username'] = $new_member->getUsername();
                    $data['language'] = $new_member->getLanguageCode();
                    $data['property'] = $new_member->getProperty();

                }
//                Request::sendMessage([
//                    'chat_id'   => 522625209,
//                    'text'      => json_encode($new_members)
//                ]);
            }

//                Request::sendMessage([
//                    'chat_id'   => 522625209,
//                    'text'      => json_encode($left_members)
//                ]);

            return Request::emptyResponse();
        }

        if (!$chat->isGroupChat() && !$chat->isSuperGroup()) {
            // Try to continue any active conversation.
            if ($active_conversation_response = $this->executeActiveConversation()) {
                return $active_conversation_response;
            }elseif(!$this->message->getViaBot()){
                $this->getTelegram()->executeCommand('assistant');
            }
        }

        return Request::emptyResponse();
    }
}
