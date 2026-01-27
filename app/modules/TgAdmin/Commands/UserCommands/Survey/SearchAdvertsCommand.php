<?php

namespace Modules\TgAdmin\Commands\UserCommands;

use Bot\Exception\BotException;
use Bot\Exception\TelegramApiException;
use Longman\TelegramBot\Commands\UserCommand;
use Longman\TelegramBot\Conversation;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Longman\TelegramBot\Entities\InlineKeyboardButton;
use Longman\TelegramBot\Entities\Keyboard;
use Longman\TelegramBot\Entities\KeyboardButton;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Request\TelegramEbRequest;
use Modules\TgAdmin\Commands\MyUserCommand;
use TelegramModels\TelegramEbAdvert;

/**
 * searchadverts command
 */
class searchadvertsCommand extends MyUserCommand
{

//
//    protected $conversation;
    protected $search_id;
//    private $inline_key_data;
//    private $chat_id;
//    private $message_id;

    protected function constructor()
    {
        parent::constructor();

        $this->name = 'searchadverts';
        $this->description = 'Search Adverts command';
        $this->usage = '/searchadverts';
        $this->version = '1.1.0';
        $this->is_button_next = false;

        $this->params = array(
            'category' => "/Оренда|Продаж/",
            'type' => "/Квартира|Будинок|Ділянка|Комерція/",
            'city' => "",
            'region' => "", //"/Галицький|Шевченківський|Франківський|Сихівський|Залізничний|Личаківський/",
            'street' => '',
            'rooms'=> "/1к|1-2к|2-3к|3к+/",
            'floor' => "/Не перший|Від 5го|Від 10го|Останній|Перший|До 4го|До 9го|Не останній/",
            'floors' => "/(^[1-9]$)|(^([1-2])([0-9])$)/",
            'material' => "",
            'is_new' => "/Так|Ні/",
            'square_full' => '/^((\d{1,3}\.\d{1,2})|(\d{1,3}))$/',
            'square_from' => '/^((\d{1,3}\.\d{1,2})|(\d{1,3}))$/',
            'square_to' => '/^((\d{1,3}\.\d{1,2})|(\d{1,3}))$/',
            'square_ground'=> '/^((\d{1,3}\.\d{1,2})|(\d{1,3}))$/',
            'object_type'=> "/Чешка|Сталінка|Хрущовка|Австрійка|Малосімейка|Окремостоячий|Спарка|Котедж|Дача|Офіс|Магазин|Склад|Виробництво|Під будівництво|Під СГ/",
            'condition'=> '/0-цикл|потребує ремонту|житловий стан|косметичний ремонт|євроремонт|дизайнерський ремонт/',
            'wall_material'=> '/цегла|панель|газоблок|бетон|дерево|піноблок/',
            'description'=> '',
            'price_from'=> '/^\d{3,7}$/',
            'price_to'=> '/^\d{3,7}$/',
            'contact_phone'=> '/^0\d{9}$/',
            'contact_name'=> '',
            'location'=> '',
            'address'=> '',
            'photos'=> '',
            'end' => ''
        );

        $this->pages_data = array( //todo звести всі 'is_new' до спільного типу аргументів (int/bool)
        "category" =>[[
            [
                'text' => 'Оренда',
                'callback_data' => 'searchadverts;category;rent'
            ],
            [
                'text' => 'Продаж',
                'callback_data' => 'searchadverts;category;sale'
            ]
        ]],
        "type" => [
            [
                [
                    'text' => 'Квартира',
                    'callback_data' => 'searchadverts;type;flat',
                ],
                [
                    'text' => 'Будинок',
                    'callback_data' => 'searchadverts;type;build',
                ],
            ],
            [
                [
                    'text' => 'Ділянка',
                    'callback_data' => 'searchadverts;type;ground',
                ],
                [
                    'text' => 'Комерція',
                    'callback_data' => 'searchadverts;type;commerce',
                ]
            ]
        ],
        "region" => [
            [
                [
                    'text' => 'Галицький',
                    'callback_data' => 'searchadverts;type;gal',
                ],
                [
                    'text' => 'Шевченківський',
                    'callback_data' => 'searchadverts;type;shev',
                ],
            ],
            [
                [
                    'text' => 'Франківський',
                    'callback_data' => 'searchadverts;type;fran',
                ],
                [
                    'text' => 'Сихівський',
                    'callback_data' => 'searchadverts;type;sikh',
                ]
            ],
            [
                [
                    'text' => 'Залізничний',
                    'callback_data' => 'searchadverts;type;zal',
                ],
                [
                    'text' => 'Личаківський',
                    'callback_data' => 'searchadverts;type;lich',
                ]
            ]
        ],
        "is_new" => [[
            [
                'text' => 'Так',
                'callback_data' => 'searchadverts;is_new;1',
            ],
            [
                'text' => 'Ні',
                'callback_data' => 'searchadverts;is_new;0',
            ]
        ]],
        "rooms" => [[
            [
                'text' => '1к',
                'callback_data' => 'searchadverts;rooms;1r',
            ],
            [
                'text' => '1-2к',
                'callback_data' => 'searchadverts;rooms;12r',
            ],
            [
                'text' => '2-3к',
                'callback_data' => 'searchadverts;rooms;23r',
            ],
            [
                'text' => '3к+',
                'callback_data' => 'searchadverts;rooms;3r',
            ]
        ]],
        "floor" => [[
            [
                'text' => 'Не перший',
                'callback_data' => 'searchadverts;floor;2f'
            ],
            [
                'text' => 'Від 5го',
                'callback_data' => 'searchadverts;floor;5f'
            ],
            [
                'text' => 'Від 10го',
                'callback_data' => 'searchadverts;floor;10f'
            ],
            [
                'text' => 'Останній',
                'callback_data' => 'searchadverts;floor;mf'
            ]
        ],[
            [
                'text' => 'Перший',
                'callback_data' => 'searchadverts;floor;1f'
            ],
            [
                'text' => 'До 4го',
                'callback_data' => 'searchadverts;floor;4f'
            ],
            [
                'text' => 'До 9го',
                'callback_data' => 'searchadverts;floor;9f'
            ],
            [
                'text' => 'Не останній',
                'callback_data' => 'searchadverts;floor;nmf'
            ]
        ]],
        "floors" => [[
            [
                'text' => 'Перший',
                'callback_data' => 'searchadverts;floors;1f'
            ],
            [
                'text' => 'До 4го',
                'callback_data' => 'searchadverts;floors;4f'
            ],
            [
                'text' => 'До 7го',
                'callback_data' => 'searchadverts;floors;7f'
            ],
            [
                'text' => 'До 10го',
                'callback_data' => 'searchadverts;floors;10f'
            ],
            [
                'text' => 'Не останній',
                'callback_data' => 'searchadverts;floor;nmf'
            ],
        ]],
        "object_type" => array(
            "Квартира" => [[
                ['text' => 'Чешка'],
                ['text' => 'Хрущовка'],
                ['text' => 'Австрійка'],
            ],[
                ['text' => 'Сталінка'],
                ['text' => 'Малосімейка'],
            ]
            ],
            "Будинок" => [[
                ['text' => 'Окремостоячий'],
                ['text' => 'Спарка'],
            ],[
                ['text' => 'Котедж'],
                ['text' => 'Дача'],
            ]
            ],
            "Комерція" => [[
                ['text' => 'Офіс'],
                ['text' => 'Магазин'],
            ],[
                ['text' => 'Склад'],
                ['text' => 'Виробництво'],
            ]
            ],
            "Ділянка" => [[
                ['text' => 'Під будівництво'],
                ['text' => 'Під СГ'],
            ]]
        ),
        "condition" => [[
            ['text' => '0-цикл'],
            ['text' => 'потребує ремонту'],
        ],[
            ['text' => 'житловий стан'],
            ['text' => 'косметичний ремонт'],
        ],[
            ['text' => 'євроремонт'],
            ['text' => 'дизайнерський ремонт'],
        ]],
        "wall_material" => [[
            ['text' => 'цегла'],
            ['text' => 'панель'],
        ],[
            ['text' => 'газоблок'],
            ['text' => 'бетон'],
        ],[
            ['text' => 'дерево'],
            ['text' => 'піноблок'],
        ]],
        "contact_phone" => [[
            array('text' => 'Надіслати мій номер', 'request_contact' => true),
        ]],
        "contact_name" => [[
            array('text' => 'Залишити поточне ім\'я'),
        ]],
        "location" => [[
            array('text' => 'Надіслати поточне розташування', 'request_location' => true)
        ]],
    );
        $this->page_messages = array(
            'category' => "Надавати інформацію - моя основна задача. <b>Що ви шукаєте?</b>",
            'type' => "<b>Оберіть тип нерухомості:</b>",
            'region' => "<b>Оберіть район:</b>",
            'rooms'=> "<b>Кількість кімнат:</b>",
            'floor' => "<b>Поверх:</b>",
            'floors' => "<b>Поверхів:</b>",
            'is_new' => "<b>Це новобуд?</b>",
            'square_full' => '<b>Площа загальна:</b> (м2, формат: ххх.хх)',
            'square_from' => '<b>Площа від:</b> (м2, формат: ххх.хх)',
            'square_to' => '<b>Площа до:</b> (м2, формат: ххх.хх)',
            'square_ground'=> '<b>Площа ділянки:</b>  (сот., формат: ххх.хх)',
            'object_type'=> "<b>Тип об'єкту:</b>",
            'condition'=> '<b>Стан:</b>',
            'wall_material'=> '<b>Матеріал стін:</b>',
            'description'=> '<b>Опис:</b> (попередній перегляд до 300 символів)',
            'price_from'=> '<b>Ціна від:</b>',
            'price_to'=> '<b>Ціна до:</b>',
            'contact_phone'=> '<b>Контактний номер:</b> (Ви можете вибрати контакт колеги натиснувши на скріпку, далі Контакт)',
            'contact_name'=> '<b>Ім\'я:</b>',
            'location'=> '<b>Розташування:</b> (Натисніть на скріпку, виберіть Розташування та поставте мітку на карті)',
            'address'=> '<b>Адреса:</b>',
            'photos'=> '<b>Фото:</b>',
            'end'=> 'Використано всі пошукові фільтри. ' . PHP_EOL . '<b>Введіть назву нового пошуку для збереження. </b>',
            'send'=> 'Назва пошуку - <b>%s</b>'. PHP_EOL .'ID - <b>%s</b>',
    //        'stop'=> '<b>Оголошення успішно збережено та надіслано на наш канал @estatebook</b>',
        );
        $this->optional_list = array(
//            'floor' => true,
        );
    }

    protected function preStart($params = array()){

    }

    protected function setNextState($pre_state){

        switch ($pre_state) {
            case 'start':
                $this->notes['state'] = "category";
                break;
            case 'category':
                $this->notes['state'] = 'type';
                break;
            case 'type':
                if(!$this->notes['type']){$this->notes['state'] = 'type';}
                if($this->notes['type'] == 'Квартира' || $this->notes['type'] == 'Будинок') {
//                    $notes['state'] = 'is_new';
                    $this->notes['state'] = 'region';
                }elseif($this->notes['type'] == 'Ділянка' || $this->notes['type'] == 'Комерція'){
//                    $notes['state'] = 'object_type';
                    $this->notes['state'] = 'region';
                }
                break;
//            case 'is_new':
//                if($notes['type'] == 'Будинок' || ($notes['type'] == 'Квартира' && $notes['is_new'] == 'Ні')) {
//                    $notes['state'] = 'object_type';
//                }elseif(!$notes['is_new'] || ($notes['type'] == 'Квартира' && $notes['is_new'] == 'Так') ||  $notes['is_new'] == 'Не важливо'){
//                    $notes['state'] = 'region';
//                }
//                break;
//            case 'object_type':
//                $notes['state'] = 'region';
//                break;
            case 'region':
                if($this->notes['type'] == 'Квартира' || $this->notes['type'] == 'Комерція' || $this->notes['type'] == 'Будинок' ) {
                    $this->notes['state'] = 'rooms';
                }elseif($this->notes['type'] == 'Ділянка'){
                    $this->notes['state'] = 'square_ground';
                }
                break;
            case 'rooms':
                if($this->notes['type'] == 'Квартира' || $this->notes['type'] == 'Комерція') {
                    $this->notes['state'] = 'floor';
                }elseif($this->notes['type'] == 'Будинок'){
//                    $this->notes['state'] = 'floors';
                    $this->notes['state'] = 'square_from';
                }
                break;
            case 'floor':
                if($this->notes['type'] == 'Квартира') {
//                    $this->notes['state'] = 'floors';
                    $this->notes['state'] = 'square_from';
                }elseif($this->notes['type'] == 'Комерція'){
                    $this->notes['state'] = 'square_from';
                }
                break;
//            case 'floors':
//                $notes['state'] = 'square_full';
//                break;
//            case 'square_full':
//                if($notes['type'] == 'Квартира' || $notes['type'] == 'Будинок') {
//                    $notes['state'] = 'square_from';
//                }elseif($notes['type'] == 'Комерція'){
//                    $notes['state'] = 'price_from';
//                }
//                break;
            case 'square_from':
                $this->notes['state'] = 'square_to';
                break;
            case 'square_to':
                if($this->notes['type'] == 'Квартира'){
                    $this->notes['state'] = 'price_from';
                }
                elseif($this->notes['type'] == 'Будинок') {
//                    $this->notes['state'] = 'square_ground';
                    $this->notes['state'] = 'price_from';
                }
                break;
//            case 'square_ground':
//                if($this->notes['type'] == 'Будинок'){
//                    $this->notes['state'] = 'price_from';
//                }
//                elseif($this->notes['type'] == 'Ділянка') {
//                    $this->notes['state'] = 'description';
//                }
//                break;
            case 'price_from':
                $this->notes['state'] = 'price_to';
                break;
            case 'price_to':
                $this->notes['state'] = 'end';
                break;
//            case 'wall_material':
//                $notes['state'] = 'description';
//                break;
//            case 'description':
//                $notes['state'] = 'condition';
//                break;
//            case 'condition':
//                $notes['state'] = 'contact_phone';
//                break;
//            case 'contact_phone':
//                $m_data = $message->getRawData();
//                $notes['state'] = 'contact_name';
//                break;
//            case 'contact_name':
//                $notes['state'] = 'address';
//                break;
//            case 'address':
//                $notes['state'] = 'photos';
//                break;
//            case 'photos':
//                $notes['state'] = 'end';
//                break;
            case 'end':
                //               $this->notes['state'] = 'send';

                Request::sendMessage([
                    'chat_id' => $this->chat_id,
                    'text' => 'Завершено',
                    'reply_markup' => Keyboard::remove(['selective' => true])
                ]);
                break;
            case 'send':
                $this->conversation->stop();
                $this->notes['send'] = 'stop';
//                return Request::emptyResponse();
                break;
            default:
                $this->conversation->cancel();
                $data['text'] = "Некорректна команда! Зверніться до адмінінстратора.";
                $data['reply_markup'] = Keyboard::remove(['selective' => true]);
                return Request::sendMessage($data);
                break;
        }
    }

    protected function end($default_data = array()){
        if($this->message->getText()){
            // подає оголошення на канал, додає хеш_теги
            //$chat_id = $notes["category"] == 'Оренда' ? '@estatebookrent' : '@estatebooksale';
//                    $inline_keyboard[] = [
//                        new InlineKeyboardButton(
//                            [
//                                'text'          => 'Отримати фото',
//                                'callback_data' => 'getphotos;inlineKBPhotos;' . ($notes['category'] == 'Продаж' ? 's_' : 'r_'),
//                            ]
//                        ),
//                        new InlineKeyboardButton(
//                            [
//                                'text'          => 'Детальніше',
//                                'callback_data' => 'getphotos;inlineKBDesc;' . ($notes['category'] == 'Продаж' ? 's_' : 'r_')
//                            ]
//                        ),
//                    ];
//                    $result = Request::sendMessage(array(
//                        'text' => $this->getOutMessage($notes, $message->getFrom()->getUsername()),
//                        'chat_id' => $chat_id,
//                        'parse_mode' => 'HTML',
//                        'reply_markup' => new InlineKeyboard(...$inline_keyboard),
//                    ));
//                    if(!$result->getOk()){
//                        Request::sendMessage(
//                            [
//                                'chat_id' => 522625209,
//                                'text'    => json_encode($result),
//                            ]
//                        );
//                        return Request::emptyResponse();
//                    }
//
//                    // повертає message_ID, зберігає в базу даних
//
//                    $notes["message_id"] = ($notes['category'] == 'Продаж' ? 's_' : 'r_') . $result->getRawData()["result"]["message_id"];

                $this->notes["user_id"] = $this->user_id;
                $this->notes["source"] = 'adverts';
                $search_model = new TelegramEbRequest();
                $search_model->setDataFromConversation($this->notes);

                if (!$search_model->save()) {
                    $mess = '';
                    foreach ($search_model->getMessages() as $msg) {
                        $mess .= $msg;
                    }
                    throw new TelegramException('Помилка при збереженні ' . PHP_EOL . $mess);
                }
                $this->search_id = $search_model->search_id;
                // надіслати його відправнику з інструкціями
//                        Request::sendMessage(
//                            [
//                                'chat_id' => $find_vars["chat_id"],
//                                'text'    => 'ID вашого пошуку - ' . $search_model->search_id
//                            ]
//                        );


        }else{
            $this->conversation->update();
        }
    }

    private function editMessage($text, $page, $chat_id, $message_id){

        $result = Request::editMessageText(
            [
                'chat_id'                  => $chat_id,
                'message_id'               => $message_id,
                'text'                     => $text,
//                'reply_markup'             => $inline_keyboard,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]
        );
        if(!$result->isOk()){
            return Request::sendMessage(array(
                'text'    => $result,
                'chat_id' => 522625209
            ));
        }
        return $result;
    }

    protected function getReplyMarkup(){
        $page = $this->notes['state'];
//        Request::sendMessage(
//            [
//                'chat_id' => 522625209,
//                'text'    => $page,
//            ]
//        );
        if($page == 'stop'){
            return Keyboard::remove();
        }

        if ( in_array($page, array('square_full','square_from','square_to','description','price_from','price_to','description', 'end'))){
            $page = null;
//            if($page == 'square_full'){
//                $page = false;
//            }else {
//                return false;
//            }
        }
        $inline_keyboard = array();
        if ($page == 'send'){
            $inline_keyboard[] = array(
                new InlineKeyboardButton(['text' => 'Пошук в своєму чаті', 'switch_inline_query_current_chat' => $this->search_id]),
                new InlineKeyboardButton(['text' => 'Пошук у вибраному чаті', 'switch_inline_query' => $this->search_id])
            );
            return (new InlineKeyboard(...$inline_keyboard));
       }elseif ($page){
            if ($page == 'object_type'){
                $inline_keyboard = $this->pages_data['object_type'][$this->notes['type']];
            }else {
                $inline_keyboard = $this->pages_data[$page];
            }
        }

        $inline_keyboard_line_menu1 = array(
            new KeyboardButton([
                'text' => '⬅_Назад_',
                'callback_data' => 'searchadverts;command;prev',
            ]),
            new KeyboardButton([
                'text' => '➡_Вперед_',
                'callback_data' => 'searchadverts;command;next',
            ]));
        $inline_keyboard_line_menu2 = array(
            new KeyboardButton([
                'text' => '▶_Пошук_',
                'callback_data' => 'searchadverts;command;search',
            ]),
            new KeyboardButton([
                'text' => '⏹_Вихід_',
                'callback_data' => 'searchadverts;command;exit',
            ])
        );

//        $lastState = $this->getLastPreState($notes);
//        $state = $notes["state"];
//
//        if(isset($notes[$state])){
//            $inline_keyboard_line[] =
//                new KeyboardButton([
//                    'text' => '➡_Вперед_',
//                    'callback_data' => 'searchadverts;next',
//                ]);
//        }
        $inline_keyboard[] = $inline_keyboard_line_menu1;
        $inline_keyboard[] = $inline_keyboard_line_menu2;
        return (new Keyboard(...$inline_keyboard))->setResizeKeyboard(true);
    }

    protected function setParam(){
        $lastState = $this->getLastPreState();
        if (!$lastState) {
            return false;
        }
        $text = $this->input_text;

        if ($lastState == 'contact_phone' && $contact = $this->message->getContact()) {
            // choose phone number from text and validate it or if empty, get it from contact cvv
            $this->notes['contact_phone'] = $contact->getPhoneNumber();
            $this->notes['contact_name'] = $contact->getFirstName();
            $this->notes['contact_id'] = $contact->getUserId();
            return true;
        }
        if ($lastState == 'location' || $lastState == 'photos') {
            if ($location = $this->message->getLocation()) {
                $this->notes['location'] = 'Address';
                $this->notes['latitude'] = $location["latitude"];
                $this->notes['longitude'] = $location["longitude"];
                return true;
            } elseif ($photo = $this->message->getPhoto()) {

                if ($this->message->getMediaGroupId()) {
                    $this->notes["media_group_id"] = $this->message->getMediaGroupId();
                }
                $max_photo = end($photo);
                if (!isset($this->notes["photos_id"])) {
                    $this->notes["photos_id"] = array();
                }
                $this->notes["photos_id"][] = $max_photo->getFileId();
                $this->notes["photos"] = count($this->notes["photos_id"]);

                return true;
            } elseif ($document = $this->message->getDocument()) {
                $mime_type = $document->getMimeType();
                if (strpos($mime_type, 'jpeg') || strpos($mime_type, 'png')) {
                    $this->notes["photos_id"][] = $document->getFileId();
                    return true;
                }
                // should be exception
                $this->replyToChat('Фото меє бути у форматі *.jpg або *.png . Також поставте галочку навпроти пункту "Стиснути зображення"');
                return false;
            } else {
                return false;
            }
        }

        if ($lastState == 'contact_name' && $text == "Залишити поточне ім'я") {
            return true;
        }

        $is_valid = $this->validParams($lastState, $text);
        if(!$is_valid["is_ok"]){
            return false;
        };

        if($lastState == 'category') {
            $this->notes["currency"] = ($text == "Оренда") ? 'грн' : '$';
        }
        if($lastState == 'square_full') {
            $this->notes["square_from"] = 0;
            $this->notes["square_to"] = 0;
        }

        $this->notes[$lastState] = $text;

        return true;
    }

    protected function getPreOutMessage(){
        $text = 'Шукаю: ' . ($this->notes['category'] ? '<b>' . self::$translate_data[$this->notes['category']] . '</b>': '(<i>продаж, оренда</i>)') . PHP_EOL;
        $text .= $this->notes['rooms'] ? '<b>' . $this->notes['rooms'] . '</b> ' : '';
        $text .= $this->notes['type'] ? '<b>' . self::$translate_data[$this->notes['type']] . '</b>' : '';
        $text .= $this->notes['is_new'] == 'yes' ? ', новобуд' : '';
        $text .= $this->notes['region'] ? ', <b>' . $this->notes['region'] . '</b> р-н.' : '';
        $text .= $this->notes['floor'] ? PHP_EOL . 'Поверх:  <b>' . $this->notes['floor'] . '</b>' : '';
        $text .= $this->notes['square_from'] || $this->notes['square_to'] ? PHP_EOL . 'Площа:' : '';
        $text .= $this->notes['square_from'] ? ' - від <b>' . $this->notes['square_from'] . 'м2</b>' : '';
        $text .= $this->notes['square_to'] ? ' - до: <b>' . $this->notes['square_to'] . 'м2</b>' : '';
        $text .= $this->notes['price_from'] || $this->notes['price_to'] ? PHP_EOL . 'Вартість:' : '';
        $text .= $this->notes['price_from'] ? ' - від <b>' . $this->notes['price_from'] . $this->notes['currency'].'</b>' : '';
        $text .= $this->notes['price_to'] ? ' - до <b>' . $this->notes['price_to'] . $this->notes['currency'].'</b>' : '';


//        $text .= $this->notes['floor'] || $this->notes['floors'] ? PHP_EOL . '<b>Поверх:</b> ' . ($this->notes['floor']??'-') . '/' . ($this->notes['floors']??'-' ): '';
//        $text .= $this->notes['square_ground'] ? PHP_EOL . '<b>Площа ділянки:</b>  ' . $this->notes['square_ground'] . 'сот.' : ' ';
        $suffix = PHP_EOL . PHP_EOL  . '-------------------'. PHP_EOL;
        $s_message = $this->page_messages[$this->notes['state']];

        $suffix .= $this->notes['state'] == 'send' ? sprintf($s_message, $this->notes['end'], $this->search_id) : $s_message;

        return mb_convert_encoding( $text . $suffix, "UTF-8");
    }

}