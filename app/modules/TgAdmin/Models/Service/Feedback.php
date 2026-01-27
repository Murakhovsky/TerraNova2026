<?php

namespace Modules\TgAdmin\Models\Service;

use Phalcon\Mvc\Model;
use Clients;

class Feedback extends Model
{
    public int $id;
    public int $client_id;
    public string $feedback;
    public int $rating;
    public string $created_at;

    public function initialize()
    {
        $this->setSource('user_feedback');
        $this->belongsTo(
            'client_id',
            Clients::class,
            'id',
            [
                'alias' => 'user_clients'
            ]
        );
    }
}
