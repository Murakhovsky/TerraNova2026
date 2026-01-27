<?php
namespace Modules\TgAdmin\Renderer;
use Modules\TgAdmin\Featuring\Buttons;
use Modules\TgAdmin\Featuring\Messages;
use Modules\TgAdmin\Models\Estate\Objects;
use Modules\TgAdmin\Models\Estate\ObjectsDisabled;
use Modules\TgAdmin\Models\Featuring\BaseModel;
use Modules\TgAdmin\Models\Person\AppUsers;
use Modules\TgAdmin\Models\Service\Lists;

class ObjectCardBuilder extends BaseCardBuilder
{
    public function __construct($notes)
    {
        $this->name = 'new_object';
        parent::__construct($notes);
    }

    public function getTittle(): string
    {
        $currency = $this->notes['currency'] ?? 'UAH';
        $price = isset($this->notes['price_' . $currency]) ? $this->notes['price_' . $currency] : $this->notes['price'];

        $text = $this->notes['category'] ? $this->t($this->notes['category']) . ' ': '';
        $text .=  $this->notes["rooms"] ? $this->notes["rooms"] . 'кім. ' : '';
        if ($this->notes['object_type'] == 'full' || $this->notes['object_type'] == 'part'){
            $text .= $this->notes['type'] ? ' <b>' . $this->t($this->notes['type']) . '.</b>  ': ' ...';
            $text .= $this->t($this->notes['object_type']) . '. ';
        }else{
            if ($this->notes['object_type'] && ($this->notes['type'] == 'house' || $this->notes['type'] == 'commerce')) {
                $text .= $this->notes['object_type'] ? ' ' . $this->notes['object_type'] : '';
            }
            else {
                $text .= $this->notes['type'] ? $this->t($this->notes['type']) : ' ...';
                $text .= $this->notes['object_type'] ? ', ' . $this->notes['object_type'] : '';
            }
        }
        $text .= $this->notes["street"] ? ' по вул.' . $this->notes["street"] : '';
        $text .= $price ? ', ' . $price . $currency : '';

        return $text;
//        return $this->t($this->notes["category"]) . ' ' . $this->notes["rooms"] . 'кім '
//            . (defined('TG_' . $this->notes["type"]) ? $this->t($this->notes["type"]) . ' ' : '') . 'по вул.' . $this->notes["street"]
//            . ($this->notes[$price] ? ', ' . $this->notes[$price] . $this->notes["currency"] : '');
    }
    public function getTGText(array $params = array()): string
    {
        //        $realty_data['user_name'] = $this->inline_query->getFrom()->getUsername();
//        Messages::sendMeMessage('$this->price');

        $currency = $this->notes['currency'] ?? 'USD';
        $price = isset($this->notes['price']) ? $this->notes['price'] : $this->notes['price_' . $currency];

        $text = '<b>' . $this->getTittle($this->notes) . '</b>' . PHP_EOL;

        $text .= isset($this->notes['status']) ? PHP_EOL . '<b>Статус</b>: ' . $this->t($this->notes['status']) : '';
        $text .= isset($this->notes['beds']) ? PHP_EOL . '<b>Місць</b>: ' . $this->notes['beds'] : '';
        $text .= $this->notes['is_new'] == 'yes' ? ', новобуд' : '';
        $text .= $this->notes['city'] || $this->notes['region'] ? PHP_EOL . '<b>Район/Місто: </b>' : '';
        $text .= $this->notes['region'] ? $this->t($this->notes['region']) . ' / ' : '';

        $text .= $this->notes['city'] ? $this->t($this->notes['city']) : '';
        $text .= $this->notes['street'] ? PHP_EOL . '<b>Вулиця: </b>' . $this->notes['street'] : '';

        $text .= $this->notes['floor'] || $this->notes['floors'] ? PHP_EOL . '<b>Поверх:</b> ' . ($this->notes['floor'] ?? '-') . '/' . ($this->notes['floors'] ?? '-') : '';
        $square_dwelling = $this->notes['square_dwelling'] ? $this->notes['square_dwelling'] : '-';
        $square_kitchen = $this->notes['square_kitchen'] ? $this->notes['square_kitchen'] : '-';
        $text .= $this->notes['square_full'] ? PHP_EOL . '<b>Площа:</b>  ' . $this->notes['square_full'] . '/' . $square_dwelling . '/' . $square_kitchen . ' м2' : '';
        $text .= $this->notes['square_ground'] ? PHP_EOL . '<b>Площа ділянки:</b>  ' . $this->notes['square_ground'] . 'сот.' : ' ';
        $text .= $this->notes['condition'] ? PHP_EOL . '<b>Стан:</b> ' . $this->notes['condition'] : ' ';
        $text .= $this->notes['wall_material'] ? PHP_EOL . '<b>Матеріал стін:</b> ' . $this->notes['wall_material'] : '';
        if($params["is_full_description"] === true) {
            $text .= $this->notes['description'] ? PHP_EOL . '<b>Опис:</b> ' . substr(strip_tags($this->notes['description']), 0, 6900) : '';
        }else{
            $text .= $this->notes['description'] ? PHP_EOL . '<b>Опис:</b> ' . (isset($this->notes['description'][300]) ? substr(strip_tags($this->notes['description']), 0, 300) . '...' : strip_tags($this->notes['description'])) : '';
        }
//        $text .= $this->notes['description'] ? PHP_EOL . '<b>Опис:</b> ' . (isset($this->notes['description'][600]) ? substr(strip_tags ($this->notes['description']), 0, 600) . '...' : strip_tags($this->notes['description'])) : '';
//        $text .= $this->notes['latitude'] ? PHP_EOL . 'Координати: ' . substr($this->notes['latitude'], 0, 9) . ', ' . substr($this->notes['longitude'], 0, 9): ' ';
        $text .= isset($this->notes['photos']) ? PHP_EOL . '<b>Додано:</b> ' . $this->notes['photos'] . ' фото' : ' ';
//        $text .= $this->notes['term'] ? PHP_EOL . '<b>Термін:</b> ' . $this->t($this->notes['term']) : ' ';
        $text .= $price ? PHP_EOL . '<b>Ціна:</b> ' . $price . ($currency == 'USD' ? '$' : 'грн'): '';
//        $text .= PHP_EOL . '---------------------------------------------------';
        if($params["is_this_user"] || !$this->notes["user_id"]) {
            $text .= $this->notes['contact_name'] ? PHP_EOL . '<b>Ім\'я:</b> ' . $this->notes['contact_name'] . ' (автор)': ' ';
            $text .= $this->notes['contact_phone'] ? PHP_EOL . '<b>Телефон:</b> <a href="tel:' . $this->notes["contact_phone"] . '">' . $this->notes['contact_phone'] . '</a>' : ' ';
//            $text .= $this->notes['user_name'] ? PHP_EOL . '<b>Username:</b> @' . $this->notes['user_name'] : ' ';
        }else{

            $accountable_user = AppUsers::findFirstById($this->notes["user_id"]);
            if ($accountable_user) {
                $text .= PHP_EOL . '<b>Ім\'я:</b> ' . $accountable_user->first_name . ' ' . $accountable_user->last_name . ' (користувач)';
                $text .= PHP_EOL . '<b>Телефон:</b> <a href="' . $accountable_user->contact_phone . '">' .  $accountable_user->contact_phone  . '</a>';
                $text .= $accountable_user->username ? PHP_EOL . '<b>Username:</b> @' . $accountable_user->username  : ' ';
            }
        }
        $text .= $this->notes['published_at'] ? PHP_EOL . '<b>Опубліковано:</b>  ' . $this->notes['published_at'] : ' ';

        if($this->notes['id']) {
            $not_actual = ObjectsDisabled::find('object_id=' . $this->notes['id']);
            $text .= PHP_EOL . '<b>Актуально:</b>  ' . (count($not_actual) ? '⚠ повідомлень - ' . count($not_actual) : '✅ актуально');
        }
        return mb_convert_encoding($text, "UTF-8");
    }
    public function getTGCard(int $user_id, array $params)
    {
        Messages::sendMeMessage(json_encode($this->notes));

        Messages::sendMeMessage($this->notes["tg_user_id"]);
        $params["is_this_user"] = $user_id == $this->notes["tg_user_id"] || !$this->notes["user_id"];
        $inline_keyboard = array(
            array(
//                Buttons::getButton('Презентація', 'inlinekeyboard;infoWindow;inProgress'),
                Buttons::getButton('Отримати фото', 'inlinekeyboard;inlineKBPhotos;AdvertTG;' . $this->notes["id"]),
//                Buttons::getButton('🗒 ' . TG_REQUESTS,  'inlinekeyboard;infoWindow;inProgress'),
                Buttons::getButton(TG_ADD_TO_LIST, 'inlinekeyboard;setFavourite;getList;'. $this->notes["id"]),
            ),

//            array(
//                Buttons::getButton('🔑 Створити показ', 'showing;command;start;object_id;' . $this->id),
//                Buttons::getButton('🔑 Запит на показ', 'inlinekeyboard;infoWindow;inProgress;' . $this->id),
//            )
        );

        $favourite_str = '';
        if($params["is_this_user"]) {
            //todo rename 'submit' to 'object'
            $inline_keyboard[] =  array(
                Buttons::getButton('Презентація', 'inlinekeyboard;infoWindow;inProgress'),
                Buttons::getButton('⚠ Не актуально', 'inlinekeyboard;not_actual;AdvertTG,' . $this->id . ',AdvertTG,4,as_object'),
            );
            $inline_keyboard[] = array(
                Buttons::getButton('🗒 Подати',  'inlinekeyboard;setRecipients;getList;' . $this->id),
                Buttons::getButton('Не рекламувати',  'inlinekeyboard;infoWindow;inProgress'),
//                Buttons::getButton(TG_ADD_REMINDER,  'setreminder;command;start;object;' . $this->id),
            );


            $inline_keyboard[] = Buttons::getAdminLine('new_object', $this->id);

            $fav = new Lists();
            $favourite_list = $fav::getListByObjectId($this->id, $user_id);

            if (count($favourite_list)) {
                foreach ($favourite_list as $favourite) {
                    $favourite_str .= $favourite->list_name . '; ';
                }
            }
        }
//

        $inline_keyboard[] = Buttons::getControlLine('advertTG,' . $this->id);

        $params["is_full_description"] = true;
        $text = self::getTGText((array)$this, $params);
        $text .= ($favourite_str ? PHP_EOL . '<b>В списках:</b> ' . $favourite_str : '');

        $result_data = array(
            'parse_mode' => 'HTML',
            'reply_markup' => new InlineKeyboard(... $inline_keyboard),
//            'photo' => $img[0],
            'caption' =>  mb_convert_encoding($text, "UTF-8"),
        );


        $imgs = $this->photos_id ?? $this->photos_url;

        if ($params["is_photo"] && count($imgs)){
//            $media = array();
//            foreach ($imgs as $key => $photo) {
//                $media[] = new InputMediaPhoto([
//                    'media' => $photo,
////                'caption' => strip_tags(mb_convert_encoding(Messages::getObjectCardMessage($this, $params)), 'UTF-8')
//                ]);
//                if ($key == 9) break;
//            }
//            $result_data['media'] = $media;
//            $result_data['photo'] = $imgs[0];
        } else {
//            $img_links = isset($this->photos_url) ? json_decode($this->photos_url) : json_decode($this->img_links);
//            $text .= '<a href="' . $img_links[0] . '">.</a>';
        }
        $result_data['message_text'] =  mb_convert_encoding($text, "UTF-8");
        $result_data['text'] =  mb_convert_encoding($text, "UTF-8");

        return $result_data;
    }
    public function getDescription()
    {
        $price = 'price_' . (isset($this->currency) ? $this->currency : 'UAH');
        return TG_FLOOR . ': ' . ($this->floor ?? '-' )
            . '/' . ($this->floors ?? '-') . '   '
            . TG_SQUARE . ': ' . ($this->square_full ?? '-')
            . '/' . ($this->square_dwelling ?? '-')
            . '/' . ($this->square_kitchen ?? '-') . 'м2' . PHP_EOL
            . TG_PRICE.': ' . ($this->$price ?? '-') . ' ' . $this->currency . PHP_EOL;
    }
    public function getThumbUrl()
    {
        return isset($this->photos_url[0]) ? $this->photos_url[0] : '/img/logos/logo.png';
    }

    public function render(): array{
        $result = [
//            'text'       => "**{$this->title}**\n\n{$this->text}",
            'reply_markup' => [
                'inline_keyboard' => array_chunk(
                    array_map(fn($b) => [$b], $this->buttons),
                    2
                )
            ]
        ];

        if ($this->photo) {
            // якщо є фото — повернути як photo message
            return [
                'photo'      => $this->photo,
                'caption'    => $result['text'],
                'reply_markup' => $result['reply_markup']
            ];
        }

        return $result;
    }
    protected function getInlineData($params = array()): string{
        $result = array();
//        Messages::sendMeMessage("test1");
        $result["title"] = $this->getTittle((array)$this);
//        Messages::sendMeMessage("test2");
        $result["description"] = $this->getDescription();
//        Messages::sendMeMessage("test3");
        if($thumbURL = $this->getThumbUrl()) {
            $result["thumb_url"] = str_contains($thumbURL, "https:") ? $thumbURL : DOMAIN_NAME . $thumbURL;
        }
        Messages::sendMeMessage("test4");
        $TGCard = $this->getTGCard($params["user_id"], $params);
        Messages::sendMeMessage("test5");exit;
        return $result + $TGCard;
    }

    public function setPhoto(string $fileIdOrUrl): self
    {
        $this->photo = $fileIdOrUrl;
        return $this;
    }

    public function addButton(string $label, string $callbackData): self
    {
        $this->buttons[] = ['text' => $label, 'callback_data' => $callbackData];
        return $this;
    }

    public function getPreOutMessage($params = array()):string{

        $text = $this->getTGText($params);

        $suffix = $this->notes['is_private'] ? PHP_EOL . '<b>Приватний:</b> ' . $this->t($this->notes['is_private']) : ' ';
        $suffix .= PHP_EOL . PHP_EOL . '-------------------' . PHP_EOL;
        $suffix .= $this->pm($this->name, $this->notes['state']) . PHP_EOL;

        $currency = $this->notes['state'] == 'price' ? ' (' . $this->notes['currency'] . ')' : '';

        return mb_convert_encoding($text . $suffix . $currency, "UTF-8");
    }


}