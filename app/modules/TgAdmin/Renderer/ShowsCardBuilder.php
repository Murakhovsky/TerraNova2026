<?php

namespace Modules\TgAdmin\Renderer;
use Modules\TgAdmin\Featuring\Buttons;
use Modules\TgAdmin\Featuring\Messages;
use Modules\TgAdmin\Models\Estate\Objects;
use Modules\TgAdmin\Models\Person\AppUsers;
use Modules\TgAdmin\Models\Request\Requests;
use Modules\TgAdmin\Models\Service\Lists;

class ShowsCardBuilder extends BaseCardBuilder
{

    public function __construct($notes)
    {
        $this->name = 'request';


        parent::__construct($notes);
    }

    /**
     * @return string
     */


    public function getTittle(array $data): string
    {
        $date = new \DateTime($data["showing_at"]);
        return 'Показ від ' . $this->t($data["client_type"]) . '; Час: ' . $date->format('H:i - d/m');
    }
    public function getTGText(array $params = array()): string
    {
        $text = '<b>Показ: </b>'. PHP_EOL ;
        $text .= $this->notes['client_type'] ? '- від ' . ( $this->notes['client_type'] == 'owner' ? 'власника' : 'орендаря' ) . PHP_EOL : ' ';
        $text .= isset($this->notes['is_cooperation']) ? ($this->notes['is_cooperation'] ? "- через співпрацю" : "- без співпраці") . PHP_EOL : '';
        $text .= $this->notes['request_id'] ? '<b>Заявка:</b> ' . Requests::getTittleByID($this->notes["request_id"]) . PHP_EOL: ' ';
        $text .= $this->notes['object_id'] ? '<b>Об\'єкт:</b> ' . Objects::getTittleByID($this->notes['object_id']) . PHP_EOL: ' ';
//        Messages::sendMeMessage($this->notes["showing_at"]);
        $time = new \DateTime( $this->notes["showing_at"] );
        $text .= $this->notes['showing_at'] ? '<b>Час:</b> ' . $time->format('H:i (d/m)') . PHP_EOL: ' ';
        $text .= $this->notes['description'] ? '<b>Опис:</b> ' . $this->notes['description'] . PHP_EOL: ' ';
        $text .= $this->notes['status'] ? '<b>' . TG_STATUS . ':</b> ' . $this->t(($this->notes['status']) ?? $this->notes['status']) . PHP_EOL : '';
        $text .= $this->notes['accountable_id'] ? '<b>Відповідальний:</b> ' . AppUsers::getTittleByID($this->notes['accountable_id']) . PHP_EOL: ' ';
        $text .= $this->notes['result'] ? '<b>' . TG_RESULT . ':</b> ' . $this->t(($this->notes['result']) ?? $this->notes['result']) . PHP_EOL : '';

        return $text;
    }
    public function getTGCard(int $user_id, array $params)
    {
        $message = $this::getTGText((array)$this, $params);
        $message .= '<b>Автор: </b>' .  AppUsers::getTittleByID($this->author_id) . PHP_EOL;

        $inline_keyboard = array(
            array(
                Buttons::getButton('Відкрити заявку', 'inlinekeyboard;show_card;request,' . $this->request_id),
                Buttons::getButton('Відкрити об\'єкт', 'inlinekeyboard;show_card;object,' . $this->object_id),
            ),
            array(
                Buttons::getButton(TG_COMPLETE, 'showing;command;edit;' . $this->id . ';complete'),
                Buttons::getButton(TG_POSTPONE,  'showing;command;edit;' . $this->id . ';postpone'),
            ),
            array(
//                Buttons::getButton('Створити показ', 'showing;command;start;'),
                Buttons::getButton('Додати нагадування',  'inlinekeyboard;inProgress'),
            ),
        );

        if ($user_id == $this->author_id) {
            $inline_keyboard[] = Buttons::getAdminLine('showing', $this->id);
        }
        $inline_keyboard[] = Buttons::getControlLine('showing,' . $this->id);

        return array(
            'message_text' => $message,
            'reply_markup' => new InlineKeyboard(... $inline_keyboard)
        );
    }
     public function getDescription()
    {
        return $this->description;
    }
    public function getThumbUrl()
    {
        return false;
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

    public function getPreOutMessage($search_id):array{

        $text = $this->getTGText();
        Messages::sendMeMessage($this->notes['state']);
        Messages::sendMeMessage($this->pm[$this->notes['state']]);
        $s_message = $this->pm[$this->notes['state']];

        $suffix = PHP_EOL . PHP_EOL  . '-------------------'. PHP_EOL;
        Messages::sendMeMessage($this->notes['state']);
        $suffix .= $this->notes['state'] == 'send' ? sprintf($s_message, $this->notes['end'], $search_id) : $s_message;
        Messages::sendMeMessage("teset2.2");
        return mb_convert_encoding( $text . $suffix, "UTF-8");
    }


}