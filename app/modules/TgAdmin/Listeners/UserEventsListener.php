<?php
namespace Modules\TgAdmin\Listeners;

use Modules\TgAdmin\Renderer\Messages;

class UserEventsListener
{

    public function newProfileSaved($user)
    {

    }
    public function xpAdded($service, $progress, $data): void
    {
        Messages::sendMessage(
            $data["user_id"],
            "Вітаю, ви отримали " . $data['coins_count'] . "XP до свого прогресу."
        );
    }
}
