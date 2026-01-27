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
 * User "/getphotos" command
 *
 * Command to show the advert photos.
 */

use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\InputMedia\InputMediaPhoto;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Telegram\Featuring\EstateObject;
use TelegramModels\Adverts\RealtyTg;

class GetPhotosCommand extends UserCommand
{
    /**
     * @var string
     */
    protected $name = 'getphotos';

    /**
     * @var string
     */
    protected $description = 'Get advert photos from DB';

    /**
     * @var string
     */
    protected $usage = '/getphotos';

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

    private static $translate_data = array(
        'Продаж'    => 'Продається',
        'Оренда'    => 'Здається в оренду',
        'Квартира'  => 'квартира',
        'Будинок'   => 'будинок',
        'Ділянка'   => 'ділянка',
        'Комерція'  => 'комерція',
        'floor'     => 'поверх',
        'square'    => 'площа',
        '1к'        => '1 кім.',
        '2к'        => '2 кім.',
        '3к'        => '3 кім.',
        '4к'        => '4 кім.',
        '5к+'       => '5+ кім.',
    );

    public function execute(): ServerResponse
    {


        $message = $this->getMessage();
        $callback_query = $this->getCallbackQuery();
        //$chosen_inline_result = $this->getChosenInlineResult();

        if (!empty($message)) {
            $chat = $message->getChat();
            $chat_id = $message->getChat()->getId();
            $user_id = $message->getFrom()->getId();
            $user_name = $message->getFrom()->getUsername();
            $text = trim($message->getText(true));

            $data = array(
                'chat_id' => $chat_id,
                'parse_mode' => 'HTML',
            );
        } elseif (!empty($callback_query)) {

            $message = $callback_query->getMessage();
//            $message_id = $message->getMessageId();
//            $message_datatime = date("d-m H:i:s", $message->getDate());
            $result = Request::emptyResponse();

            $user_id = $callback_query->getFrom()->getId();
            $keyboard_data = explode(";", $callback_query->getData());

            $command = isset($keyboard_data[1]) ? $keyboard_data[1] : '-';
            $command_val = isset($keyboard_data[2]) ? $keyboard_data[2] : '_';
//            $text = trim($message->getText(true));

            $object_data = RealtyTg::findFirst(
                "id = '" . $command_val . "'"
            );

            switch ($command) {
                case 'inlineKBPhotos':
                    $inline_keyboard[] = [
                        new InlineKeyboardButton(
                            [
                                'text'          => 'Отримати фото',
                                'callback_data' => 'getPhotos;inlineKBPhotos;' . ($object_data->category == 'Продаж' ? 'S_' : 'R_'),
                            ]
                        ),
                        new InlineKeyboardButton(
                            [
                                'text'          => 'Детальніше',
                                'callback_data' => 'getPhotos;inlineKBDesc;' . ($object_data->category == 'Продаж' ? 'S_' : 'R_')
                            ]
                        ),
                    ];
                    $inline_keyboard = new InlineKeyboard(...$inline_keyboard);
                    Request::sendMessage([
                        'chat_id' => $user_id,
                        'text' => EstateObject::getOutMessage($object_data->toArray()),
                        'parse_mode' => 'HTML',
//                        'reply_markup' => $inline_keyboard,
                    ]);

                    $media = array();
                    foreach (json_decode($object_data->photos_id, JSON_OBJECT_AS_ARRAY) as $photo_id) {
                        $media[] = new InputMediaPhoto(['media' => $photo_id]);
                    }
                    $result = Request::sendMediaGroup([
                        'chat_id' => $user_id,
                        'media'   => $media,
                    ]);
//                    $result = Request::sendPhoto([
//                        'chat_id' => $user_id,
//                        'photo' => $photo_id
//                    ]);
                    if (!$result->isOk()) {
                        $result = Request::sendMessage([
                            'chat_id' => 522625209,
                            'text' => json_encode($result)
                        ]);
                    }
                    break;
                case 'inlineKBPhotosFromAdvert':

                    $classKeys = explode('_', $command_val);
                    $classType = '\Parser\Advert\Realty' . $classKeys[0];

                    $message_data = $classType::findFirst(
                        "id = '" . $classKeys[1] . "'"
                    );


                    $media = array();
                    $result = Request::emptyResponse();
                    if($message_data->img_links) {
//                        Request::sendMessage(
//                            [
//                                'chat_id' => $user_id,
//                                'text' => $message_data->tittle . PHP_EOL . $message_data->advert_link,
//                            ]
//                        );
                        $i = 0;
                        foreach (json_decode($message_data->img_links, JSON_OBJECT_AS_ARRAY) as $photo_url) {
                            $media[] = new InputMediaPhoto(['media' => $photo_url]);
                            if(++$i == 10 ){
                                break;
                            };

                        }
                        $result = Request::sendMediaGroup([
                            'chat_id' => $user_id,
                            'media' => $media,
                        ]);
                    }else{
                        $result = Request::sendMessage(
                            [
                                'chat_id' => $user_id,
                                'text' => "На жаль, фото немає.",
                            ]
                        );
                    }
                    //
                    if(!$result->isOk() && $callback_query = $this->getUpdate()->getCallbackQuery()) {

                        if ($result->getErrorCode() == 403) {
                            $text = 'Наш агент зможе вам відправити фото тільки після того, як ви йому напишете - @' . $this->getTelegram()->getBotUsername();
                        } else {
                            $text = 'Error: ID - ' . $result->getErrorCode() . PHP_EOL . $result->getDescription();
                        }
                        return Request::answerCallbackQuery(
                            array(
                                'callback_query_id' => $callback_query->getId(),
                                'text' => $text,
                                'show_alert' => 'true',
                            )
                        );

                    }
                    return $result;
                    break;
                case 'inlineKBMedia':
                    $data = array(
                        'chat_id' => $chat_id,
                    );
                    $data["media"] = array();
                    $j = 0;
//                    do {
//                        $data["media"][] = new InputMediaPhoto(array('media' => $photos[$i++]));
//                    } while (++$j < 10 && $photos[$i]);

                    $test = Request::sendMediaGroup($data);
//                $this->sendMessage($test);

                    //$this->sendMessage(json_encode(count($photos)));

                    break;
                case 'inlineKBDesc':
                    $temp_str = 'description description description description description description description description description description description description description description description description   ';
                    return Request::answerCallbackQuery(
                        [
                            'callback_query_id' => $callback_query->getId(),
                            'text' => "ID оголошення: " . $command_val . $message_id .
                                PHP_EOL . "Час подачі: " . $message_datatime .
                                PHP_EOL . PHP_EOL . 'Повний опис оголошення ви можете отримати разом з фото.',
                            'show_alert' => true,
                        ]
                    );
                    break;
                default:
                    break;
            }
            return $result;
        }
    }

    private function getOutMessage($message_data, $user_name = null)
    {
        $text = $message_data['category'] ? self::$translate_data[$message_data['category']] : '';
        $text .= $message_data['rooms'] ? ' ' . self::$translate_data[$message_data['rooms']] : '';

        if ($message_data['object_type'] && ($message_data['type'] == 'Будинок' || $message_data['type'] == 'Комерція')) {
            $text .= $message_data['object_type'] ? ' ' . $message_data['object_type'] : '';
        } else {
            $text .= $message_data['type'] ? ' ' . self::$translate_data[$message_data['type']] : ' ...';
            $text .= $message_data['object_type'] ? ', ' . $message_data['object_type'] : '';
        }
        $text .= $message_data['is_new'] == 'Так' ? ', новобуд' : '';
        $text .= $message_data['address'] ? PHP_EOL . '🚘 <b>' . $message_data['address'] . '</b>' : '';

        $text .= $message_data['floor'] || $message_data['floors'] ? PHP_EOL . '<b>Поверх:</b> ' . ($message_data['floor'] ?? '-') . '/' . ($message_data['floors'] ?? '-') : '';
        $square_dwelling = $message_data['square_dwelling'] ? $message_data['square_dwelling'] : '-';
        $square_kitchen = $message_data['square_kitchen'] ? $message_data['square_kitchen'] : '-';
        $text .= $message_data['square_full'] ? PHP_EOL . '<b>Площа:</b>  ' . $message_data['square_full'] . '/' . $square_dwelling . '/' . $square_kitchen . ' м2' : '';
        $text .= $message_data['square_ground'] ? PHP_EOL . '<b>Площа ділянки:</b>  ' . $message_data['square_ground'] . 'сот.' : ' ';
        $text .= $message_data['condition'] ? PHP_EOL . '<b>Стан:</b> ' . $message_data['condition'] : ' ';
        $text .= $message_data['wall_material'] ? PHP_EOL . '<b>Матеріал стін:</b> ' . $message_data['wall_material'] : '';
        $text .= PHP_EOL . '<b>Опис:</b> ' . $message_data['description'];
//        $text .= $message_data['latitude'] ? PHP_EOL . 'Координати: ' . substr($message_data['latitude'], 0, 9) . ', ' . substr($message_data['longitude'], 0, 9): ' ';
//        $text .= $message_data['photos_id'] ? PHP_EOL . 'Фотографії: ' . count($message_data['photos_id']) . ' шт.' : ' ';
        $text .= PHP_EOL . '💵 <b>Ціна:</b> ' . $message_data['price'] . $message_data["currency"] ;
        $text .= PHP_EOL . '<b>Ім\'я:</b> ' . $message_data['contact_name'];
        $text .= PHP_EOL . '<b>Телефон:</b> ' . $message_data['contact_phone'];
        $text .= $user_name ? PHP_EOL . '<b>Написати:</b> @' . $user_name : ' ';
        $text .= PHP_EOL . '<b>ID:</b> ' . $message_data['message_id'];


        $text .= PHP_EOL . PHP_EOL;

        return mb_convert_encoding($text, "UTF-8");
    }
}