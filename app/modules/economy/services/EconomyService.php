<?php

namespace Modules\Economy\Services;
use Modules\Economy\Models\Transactions;
use Modules\Economy\Models\Wallets;
use Modules\TgAdmin\Renderer\Messages;

class EconomyService
{
    public static function rewardXp(int $userId, int $amount) {  }
    public static function rewardCoins(int $userId, int $amount, string $note='') {  }
    public static function transfer(int $from, int $to, int $amount) {  }
    public function credit(int $userId, float $amount, string $currency, string $reason): void
    {
        // TODO: save to database - balance, history, etc.
        echo "[Wallet] Credited $amount $currency to user $userId: $reason\n";
    }

    public function getBalance(int $user_id, string $currency){
        return Wallets::findFirstOrCreate($user_id, $currency);
    }
}
