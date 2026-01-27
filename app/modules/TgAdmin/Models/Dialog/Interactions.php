<?php

namespace Modules\TgAdmin\Models\Dialog;

use Phalcon\Mvc\Model;
use User\Clients;

class Interactions extends Model
{
    public int $id;
    public int $client_id;
    public string $type; // тип взаємодії, наприклад, "email", "call", "meeting"
    public string $details; // деталі взаємодії
    public string $created_at;

    public function initialize()
    {
        $this->setSource('user_interactions');
        $this->belongsTo(
            'client_id',
            Clients::class,
            'id',
            [
                'alias' => 'client'
            ]
        );
    }
}
