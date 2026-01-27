<?php

/**
 * This file is part of the PHP Telegram Bot example-bot package.
 * https://github.com/php-telegram-bot/example-bot/
 *
 * (c) PHP Telegram Bot Team
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Modules\TgAdmin\Commands\UserCommands;

/**
 * User "/hidekeyboard" command
 *
 * Command to hide the keyboard.
 */

use Longman\TelegramBot\Request;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;

class HidekbCommand extends UserCommand  //hidekeyboard
{
    /**
     * @var string
     */
    protected $name = 'hidekb';

    /**
     * @var string
     */
    protected $description = 'Hide the custom keyboard';

    /**
     * @var string
     */
    protected $usage = '/hidekb';

    /**
     * @var string
     */
    protected $version = '0.2.0';

    /**
     * Main command execution
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $chat_id = $this->getMessage()->getChat()->getId();
        $conversation = new Conversation($chat_id, $chat_id, $this->getName());
        $conversation->stop();
        // Remove the keyboard and send a message

        $info_message = Request::sendMessage(
            [
                'chat_id' => $chat_id,
                'text' => '❗️Всі команди закрито',
                'reply_markup' => Keyboard::remove(),
            ]
        );
        sleep(4);
        return Request::deleteMessage([
            'chat_id' => $info_message->getResult()->getChat()->id,
            "message_id" => $info_message->getResult()->message_id
        ]);
    }
}