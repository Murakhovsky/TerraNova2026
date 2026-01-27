<?php

namespace Modules\TgAdmin\Models\Dialog;

use Modules\TgAdmin\Models\Featuring\BaseModel;

class Commands extends BaseModel
{
    public int $dialog_id;
    public string $command_alias;
    public string $command_text;
    public string $phql_query;
    public ?array $parameters = null;

    public string $status;

    public function initialize()
    {
        $this->setSource('dialog_commands');

        $this->belongsTo(
            'dialog_id',
            Dialogs::class,
            'id',
            [
                'alias' => 'dialog_dialogs'
            ]
        );
    }


    public function beforeSave()
    {
        // Перетворюємо масив параметрів у JSON перед збереженням
        if (is_array($this->parameters)) {
            $this->parameters = json_encode($this->parameters);
        }
    }

    public function afterFetch()
    {
        // Перетворюємо JSON параметри у масив після отримання з бази даних
        $this->parameters = json_decode($this->parameters, true);
    }
}