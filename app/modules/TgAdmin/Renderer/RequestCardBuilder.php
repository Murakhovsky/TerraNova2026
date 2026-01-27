<?php

namespace Modules\TgAdmin\Renderer;
use Modules\TgAdmin\Models\Service\Lists;

class RequestCardBuilder extends BaseCardBuilder
{

    public function __construct($notes)
    {
        $this->name = 'request';
        parent::__construct($notes);
    }


    public function getTittle(): string
    {
        return $this->notes["search_name"] ?? 'без назви';
    }
    public function getTGText(array $params = array()): string
    {
        $text = '<b>Заявка: </b>' . '<b>' . $this->notes['search_name'] . '</b>' . PHP_EOL . PHP_EOL;
        $text .= ($this->notes['category'] ? $this->t($this->notes['category']) . PHP_EOL : '');
//        $text .= ($this->notes['category'] == 'sale' ? TG_BY : TG_RENT) . '. ';
        $text .= ($this->notes['rooms_from'] ? ' <b>' . $this->notes['rooms_from'] . '</b>' . ($this->notes['rooms_to'] ? '-<b>' . $this->notes['rooms_to'] . '</b>' : '') . 'кім. ' : '') .$this->t($this->notes['type']);
        $text .= $this->notes['is_new'] == 'Yes' ? ', новобуд' : '';
        $text .= $this->notes['region'] ? PHP_EOL . '<b>Район: </b>' . $this->t($this->notes['region']) . ' ('  . $this->t($this->notes['city']) . ')': '';
        $text .= $this->notes['floor_from'] || $this->notes['floor_to'] ? PHP_EOL . '<b>Поверх:</b> '
            . ($this->notes['floor_from'] == '41' ? 'останній' : ($this->notes['floor_from'] == '1' ? '' : ' від <b>' . $this->notes['floor_from'] . '</b>' ))
            . (!$this->notes['floor_to'] || $this->notes['floor_to'] == 40? '' : ($this->notes['floor_to'] == '1' ? 'перший' : ($this->notes['floor_to'] == '41' ? 'не останній' : ' - до <b>' . $this->notes['floor_to'] . '</b>'))) : '';
        $text .= $this->notes['square_from'] || $this->notes['square_to'] ? PHP_EOL . '<b>Площа: </b>'
            . ($this->notes['square_from']? '  від <b>' . $this->notes['square_from'] . 'м2</b>' : '')
            . ($this->notes['square_to'] ? '  -  до: <b>' . $this->notes['square_to'] . 'м2</b>' : '') : '';
        $text .= $this->notes['square_ground_from'] || $this->notes['square_ground_to'] ? PHP_EOL . '<b>Площа ділянки: </b>'
            . ($this->notes['square_ground_from']? '  від <b>' . $this->notes['square_ground_from'] . 'сот.</b>' : '')
            . ($this->notes['square_ground_to'] ? '  -  до: <b>' . $this->notes['square_ground_to'] . 'сот.</b>' : '') : '';
        $text .= $this->notes['price_from'] || $this->notes['price_to'] ? PHP_EOL . '<b>Вартість: </b>' : '';
        $text .= $this->notes['price_from'] ? ' - від <b>' . $this->notes['price_from'] . $this->notes['currency'].'</b>' : '';
        $text .= $this->notes['price_to'] ? ' - до <b>' . $this->notes['price_to'] . $this->notes['currency'].'</b>' : '';
        $text .= $this->notes['description'] ? PHP_EOL . '<b>Опис:</b> ' . $this->notes['description'] : '';
        $text .= $this->notes['contact'] ? PHP_EOL . '<b>Номер:</b> ' . $this->notes['contact'] : '';
//        $text .= $this->notes['is_private'] ? PHP_EOL . '<b>Приватна:</b> ' . $this->t($this->notes['is_private']) : ' ';
        $text .= $this->notes['date'] ? PHP_EOL . '<b>Створено:</b> ' . $this->notes['date'] : '';
        return $text;
    }
    public function getTGCard(int $user_id, array $params)
    {
        $text = $this::getTGText((array)$this, $params);

        $inline_keyboard = array(
            array(
                Buttons::getButton('Знайти', $this->search_id, 'switch_inline_query_current_chat'),
                Buttons::getButton('Знайти  ⤴', $this->search_id, 'switch_inline_query')
            ),
            array(
                Buttons::getButton('Статус', 'inlinekeyboard;inProgress'),
                Buttons::getButton(TG_ADD_OBJECT,'inlinekeyboard;inProgress')
            ),
            array(
                Buttons::getButton(TG_ADD_TO_LIST, 'inlinekeyboard;inProgress'), //'inlinekeyboard;setFavourite;getList;' . $realty_search_data->id]),
                Buttons::getButton('Додати нагадування', 'inlinekeyboard;inProgress')
            ),
            array(
//                Buttons::getButton('🗒 ' . TG_OBJECTS, 'inlinekeyboard;inProgress'),
                Buttons::getButton('🔑 Створити показ', 'showing;command;start;request_id;' . $this->id),
                Buttons::getButton('🔑 Запит на показ', 'inlinekeyboard;inProgress;'),
            )
        );

        if ($user_id == $this->user_id) {
            $inline_keyboard[] = Buttons::getAdminLine('request', $this->id);

            $text .= $this->is_private ? PHP_EOL . '<b>Приватна:</b> ' . $this->t($this->is_private) : ' ';

            $favourite_str = '';
            $favourite_list = ListsItems::find(
                "item_id = '" . $this->id . "'"
            );
            if (count($favourite_list)) {
                foreach ($favourite_list as $favourite) {
                    $favourite = Lists::findFirst(
                        "id = '" . $favourite->list_id . "'"
                    );
                    $favourite_str .= $favourite->list_name . '; ';
                }
            }
        }
        $inline_keyboard[] = Buttons::getControlLine('request,' . $this->id);

        return array(
            'message_text' => mb_convert_encoding($text, "UTF-8"),
            'reply_markup' => new InlineKeyboard(...$inline_keyboard)
        );
    }
     public function getDescription()
    {
        return ($this->rooms_from ?? '')
            . ($this->rooms_to ?  '-' . $this->rooms_to : '+') . 'кім '
            . ($this->object_type ?? ($this->t[$this->type] ?? $this->type))
            . ($this->region ? ', ' . ($this->t($this->region) ?? $this->region) . ' р-н.' : '')
            . ($this->street ? ', по вул.' . $this->street : '')
            . ($this->floor ? PHP_EOL . 'Поверх:  ' . $this->floor : '')
            . ($this->square_from || $this->square_to ? ';   Площа:' : '')
            . ($this->square_from ? ' - від ' . $this->square_from . 'м2' : '')
            . ($this->square_to ? ' - до ' . $this->square_to . 'м2' : '')
            . ($this->price_from || $this->price_to ? PHP_EOL . 'Вартість:' : '')
            . ($this->price_from ? ' - від ' . $this->price_from . $this->currency . '' : '')
            . ($this->price_to ? ' - до ' . $this->price_to . $this->currency . '' : '');
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

    public function getPreOutMessage($params = array()): string{

        $text = $this->getTGText();
        $s_message = $this->pm($this->name, $this->notes['state']);

        $suffix = PHP_EOL . PHP_EOL  . '-------------------'. PHP_EOL;
        $suffix .= $this->notes['state'] == 'send' ? sprintf($s_message, $this->notes['end'], $params["search_id"]) : $s_message;

        return mb_convert_encoding( $text . $suffix, "UTF-8");
    }


    protected function getInlineData($params = array()): string
    {
        // TODO: Implement getInlineData() method.
    }
}