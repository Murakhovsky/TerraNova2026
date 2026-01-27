<?php

namespace Modules\Economy\Models;

use Modules\TgAdmin\Renderer\Messages;
use Phalcon\Mvc\Model;

class Transactions extends Model
{
    public int $id;
    public int $user_id;
    public string $currency;
    public float $amount;
    public string $type; // 'credit', 'debit'
    public string $reason; // 'profile_bonus', 'game_reward', etc.
    public string $created_at;

    public function initialize()
    {
        $this->setSource('economy_transactions');

        $this->belongsTo('user_id', User::class, 'id', [
            'alias' => 'user'
        ]);
    }

}
