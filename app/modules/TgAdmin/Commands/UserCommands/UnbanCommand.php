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

/**
 * User "/weather" command
 *
 * Get weather info for the location passed as the parameter..
 *
 * A OpenWeatherMap.org API key is required for this command!
 * You can be set in your config.php file:
 * ['commands']['configs']['weather'] => ['owm_api_key' => 'your_owm_api_key_here']
 */

namespace Modules\TgAdmin\Commands\UserCommands;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\TelegramLog;
use Telegram\Featuring\Messages;

class UnbanCommand extends UserCommand
{
    /**
     * @var string
     */
    protected $name = 'unban';

    /**
     * @var string
     */
    protected $description = 'Un ban user';

    /**
     * @var string
     */
    protected $usage = '/unban';

    /**
     * @var string
     */
    protected $version = '1.1.0';


    /**
     * Main command execution
     *
     * @return ServerResponse
     * @throws TelegramException
     */
    public function execute(): ServerResponse
    {
        $message = $this->getMessage();
        $callback = $this->getCallbackQuery();

        if (!empty($message)){
            $chat_id = $message->getChat()->getId();
            $user_id = $message->getFrom()->getId();
            $user_name = $message->getFrom()->getUsername();
            $message_id = $message->getMessageId();
            $text = $message->getText(true);
        }
        if(!empty($callback)) {

            $user_id = $callback->getFrom()->getId();
            $user_name = $callback->getFrom()->getUsername();
            if ($callback->getMessage()) {
                $message_id = $callback->getMessage()->getMessageId();
            }
            $data = $callback->getData();
            $page = explode(';', $data)[1];
        }

        if($user_id == 522625209){

            if(strlen($text) > 8 && strlen($text) < 11 && (int)$text) {
                $request = Request::unbanChatMember(array(
                    'chat_id' => -1001379672244,
                    'user_id' => $text
                ));
                if(!$request->isOk()){
                    return Messages::sendMessage($user_id, $request->getDescription());
                }
                return Messages::sendMessage($user_id, "unbaned!");
            }else{
                return Messages::sendMessage($user_id, 'wrong ID');
            }
        }else{
            return Messages::sendMessage($user_id, "no");
        }



    }
}