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
 * Callback query command
 *
 * This command handles all callback queries sent via inline keyboard buttons.
 *
 * @see InlinekeyboardCommand.php
 */

namespace Longman\TelegramBot\Commands\SystemCommands;

use Bot\Helper\Utilities;
use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Modules\TgAdmin\Featuring\EstateObject;
use Modules\TgAdmin\Featuring\Messages;

class CallbackqueryCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'callbackquery';

    /**
     * @var string
     */
    protected $description = 'Handle the callback query';

    /**
     * @var string
     */
    protected $version = '1.2.0';

    /**
     * Callback data before first ';' symbol -> command bind
     *
     * @var array
     */
    private $aliases = [
        'stats' => 'stats',
    ];

    /**
     * Main command execution
     *
     * @return ServerResponse
     * @throws \Exception
     */
    public function execute(): ServerResponse
    {
        try{

            $callback_query = $this->getCallbackQuery();
            $data = $callback_query->getData();
    //
    //        Utilities::debugPrint('Data: ' . $data);
    //
            $command = explode(';', $data)[0];
            if ($this->getTelegram()->getCommandObject($command)) {
                return $this->getTelegram()->executeCommand($command);
            }

            Messages::sendMeMessage("Unknown command: ". $command. " \n Data: ". $data);

//            if($command == 'getmore'){
//
//                $params = explode(';', $data);
//                $getmore_param = $params[1];
//                $estate_object = EstateObject::getRealtyByID($params[2], $params[3]);
//
//                if($getmore_param == 'close'){
//                    $result = Request::editMessageText(array(
//                        'text' => Messages::getObjectPostMessage($estate_object),
//                        'message_id' => $this->getCallbackQuery()->getMessage()->getMessageId(),
//                        'chat_id' => $this->getCallbackQuery()->getMessage()->getChat()->getId(),
//                        'parse_mode' => 'HTML',
//    //                    'reply_markup' => new InlineKeyboard(...$inline_keyboard),
//                    ));
//
//                }elseif ($getmore_param == 'open'){
//
//                }
//            }
        }catch (TelegramException $e){
            Request::sendMessage(
                [
                    'chat_id' => 522625209,
                    'text'    => $e->getMessage(),
                ]
            );
        }
        return Request::answerCallbackQuery(
            [
                'callback_query_id' => $callback_query->getId(),
                'text'              => "Bad request!",
                'show_alert'        => true,
            ]
        );
    }
}
