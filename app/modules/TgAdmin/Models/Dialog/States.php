<?php

namespace Modules\TgAdmin\Models\Dialog;

use Phalcon\Mvc\Model;

class States extends Model
{
    public int $id;                   // Унікальний ідентифікатор стану діалогу
    public int $dialog_id;            // Ідентифікатор діалогу
    public string $state;             // Поточний стан діалогу, наприклад, 'awaiting_confirmation'
    public ?string $step;             // Поточний крок процесу (опціонально)
    public ?string $parameters;       // Додаткові параметри стану у форматі JSON
    public string $created_at;        // Дата створення запису про стан
    public ?string $updated_at;       // Дата останнього оновлення стану

    public function initialize()
    {
        // Вказуємо назву таблиці
        $this->setSource('dialog_states');

        // Визначаємо зв'язок з таблицею dialogs
        $this->belongsTo(
            'dialog_id',
            Dialogs::class,
            'id',
            [
                'alias' => 'dialog_states'
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
