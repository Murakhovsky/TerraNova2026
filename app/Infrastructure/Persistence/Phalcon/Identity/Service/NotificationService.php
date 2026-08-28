<?php
namespace Infrastructure\Persistence\Phalcon\Identity\Service;
use Infrastructure\Persistence\Phalcon\Identity\Telegram\Message\UserMessages;

class NotificationService
{
    public function send(int $userId, string $content): void
    {
        $message = new UserMessages();
        $message->user_id = $userId;
        $message->content = $content;
        $message->status = 'send';
        $message->created_at = date('Y-m-d H:i:s');
        $message->save();

        echo "[Notify] Message sent to user $userId: $content\n";
    }
}


