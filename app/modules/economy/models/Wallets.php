<?php

namespace Modules\Economy\Models;

use Modules\TgAdmin\Models\Person\AppUsers;
use Modules\TgAdmin\Renderer\Messages;
use Phalcon\Mvc\Model;

class Wallets extends Model
{
    public $id;
    public int $user_id;
    public $currency; // 'coin', 'uah', 'usd', etc.
    public $balance;
    public $meta;
    public $created_at;
    public $updated_at;

    public function initialize()
    {
        $this->setSource('economy_wallet');
//        $this->belongsTo(
//            'user_id',
//            AppUsers::class,
//            'id',
//            [
//                'alias' => 'user'
//            ]
//        );
    }
    public static function findFirstOrCreate(int $user_id, string $currency = 'coin'):Wallets
    {
        $wallet = self::findFirst([
            'conditions' => 'user_id = :user_id: AND currency = :currency:',
            'bind' => ['user_id' => $user_id, 'currency' => $currency],
        ]);


        if (!$wallet) {
            $wallet = new (self::class)();
            $wallet->user_id = $user_id;

            if(!$wallet->save()){
                $errors = [];
                foreach ($wallet->getMessages() as $message) {
                    $errors[] = $message->getMessage();
                }
                $errorText = implode("\n", $errors);
                Messages::sendMeMessage("❌ Помилка при збереженні гаманця:\n" . $errorText);
            };
        }
        return $wallet;
    }

    public static function addCoins(int $user_id, int $coins_count):void
    {
        $wallet = self::findFirstOrCreate($user_id);
        $wallet->balance += $coins_count;
        $wallet->save();

        di('eventService')->fire("wallet:coinAdded", $wallet, ["user_id" => $user_id, "coins_count" => $coins_count]);
    }
}
