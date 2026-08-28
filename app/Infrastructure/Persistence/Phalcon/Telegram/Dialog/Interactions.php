<?php

namespace Infrastructure\Persistence\Phalcon\Telegram\Dialog;

use Phalcon\Mvc\Model;
use Infrastructure\Persistence\Phalcon\Identity\Telegram\Company\Clients;

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

