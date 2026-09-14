<?php

namespace Domains\Sales\Infrastructure\Persistence\Phalcon\Telegram\Request;

use Domains\Property\Infrastructure\Persistence\Phalcon\Telegram\Estate\Objects;
use Infrastructure\Integration\Telegram\ActiveRecord\PresentationModel;
use Infrastructure\Integration\Telegram\InlineKeyboardFactory;
use Longman\TelegramBot\Entities\InlineKeyboard;
use Domains\Identity\Infrastructure\Persistence\Phalcon\Telegram\Person\AppUsers;


class Shows extends PresentationModel
{
    public $author_id;
    public $object_id;
    public $request_id;
    public $accountable_id;
    public $is_cooperation;
    public $client_type;
    public $status;
    public $result;
    public $description;
    public $showing_at;

    public function initialize()
    {
        $this->setSource('request_shows');
    }

    public function setDataFromConversation($notes){
        parent::setDataFromConversation($notes);
        $this->description .= PHP_EOL . PHP_EOL . '[' . date('d.m.Y H:i') . '] ('
            . $this->t($notes["status"] ). ')' . PHP_EOL . $notes["description"];
        $this->showing_at = $notes["showing_at"] . ' ' . $notes["showing_at_time"];
    }
    static function getDataArray($id)
    {
        $showin_data = self::findFirst("id='" . $id . "'");

        if (!$showin_data) {return false;}

        $result = (array)$showin_data;

        $data_time = explode(' ', $showin_data->showing_at);
        $result['description'] = null;
        $result['showing_at'] = $data_time[0] ?? null;
        $result['showing_at_time'] = $data_time[1] ?? null;

        return $result;
    }


    public function getThumbUrl()
    {
        return false;
    }

    public function getJSON(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function getWEB(array $data): string
    {
        $title = htmlspecialchars(self::getTittle($data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $description = htmlspecialchars(trim((string) ($data['description'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<article class="showing"><h3>' . $title . '</h3><p>' . nl2br($description) . '</p></article>';
    }

    public static function getTittle(array $data): string
    {
        $timestamp = strtotime((string) ($data['showing_at'] ?? ''));
        $when = $timestamp ? date('H:i d.m', $timestamp) : 'час не визначено';
        $clientType = (string) ($data['client_type'] ?? 'client');

        return 'Показ (' . $clientType . ') — ' . $when;
    }

    public function getDescription(): string
    {
        return trim(implode(' · ', array_filter([
            $this->status ? (string) $this->status : null,
            $this->result ? (string) $this->result : null,
            $this->description ? (string) $this->description : null,
        ])));
    }

    public function getTGCard(int $user_id, array $params): array
    {
        $lines = ['<b>Показ</b>'];
        if ($this->request_id) $lines[] = '<b>Заявка:</b> ' . Requests::getTittleByID($this->request_id);
        if ($this->object_id) $lines[] = '<b>Обʼєкт:</b> ' . Objects::getTittleByID($this->object_id);
        if ($this->showing_at) $lines[] = '<b>Час:</b> ' . date('H:i d.m.Y', strtotime((string) $this->showing_at));
        if ($this->description) $lines[] = '<b>Опис:</b> ' . htmlspecialchars((string) $this->description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($this->status) $lines[] = '<b>Статус:</b> ' . htmlspecialchars((string) $this->status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($this->accountable_id) $lines[] = '<b>Відповідальний:</b> ' . AppUsers::getTittleByID($this->accountable_id);
        if ($this->result) $lines[] = '<b>Результат:</b> ' . htmlspecialchars((string) $this->result, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $keyboard = [[
            InlineKeyboardFactory::button('Відкрити заявку', 'inlinekeyboard;show_card;request,' . $this->request_id),
            InlineKeyboardFactory::button('Відкрити обʼєкт', 'inlinekeyboard;show_card;object,' . $this->object_id),
        ]];
        if ($user_id === (int) $this->author_id) {
            $keyboard[] = InlineKeyboardFactory::adminLine('showing', $this->id);
        }
        $keyboard[] = InlineKeyboardFactory::controlLine('showing,' . $this->id);

        return [
            'message_text' => implode(PHP_EOL, $lines),
            'reply_markup' => new InlineKeyboard(...$keyboard),
        ];
    }
}

