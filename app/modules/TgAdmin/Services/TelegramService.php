<?php

namespace Modules\TgAdmin\Services;

use Longman\TelegramBot\Telegram;    // або твоя бібліотека
use Longman\TelegramBot\Request;

class TelegramService
{
    public function __construct(string $token, string $name)
    {

    }

    public static function getFullUserData(int $user_id): array
    {
        return [
            'xp' => AppUsers::getLvXp($user_id),
            'msg' => UserMessages::countByUser($user_id),
            'coin' => WalletService::getBalance($user_id),
        ];
        $user_data = AppUsers::getLvXp($user_id);
        $user_data["msg"] = count(UserMessages::find("user_id = '$user_id'"));
        $user_data["coin"] = Wallets::findFirst("user_id = '$user_id'")->balance;
    }

}
