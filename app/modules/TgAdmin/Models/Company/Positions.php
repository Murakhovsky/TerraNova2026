<?php

namespace Modules\TgAdmin\Models\Company;

use Phalcon\Mvc\Model;

class Positions extends Model
{
    public int $id;
    public string $title; // назва посади
    public string $description; // опис посади

    public function initialize()
    {
        $this->setSource('user_positions');
        $this->hasMany(
            'id',
            Employees::class,
            'position_id',
            [
                'alias' => 'employees'
            ]
        );
    }
}
