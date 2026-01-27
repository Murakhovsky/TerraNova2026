<?php
namespace Modules\TgAdmin\Listeners;

use Modules\TgAdmin\Renderer\Messages;

class WalletEventsListener
{
    public function coinAdded($service, $wallet, $data): void
    {
        Messages::sendMessage(
            $data["user_id"],
            "Вітаю, ви отримали " . $data['coins_count'] . "C до свого рахунку."
        );
    }
    public function editedProfileSaved($user)
    {

    }
}
