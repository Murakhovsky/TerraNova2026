<?php
declare(strict_types=1);

namespace Infrastructure\Identity;

use Domains\Identity\Infrastructure\Persistence\Phalcon\Telegram\Message\UserMessages;

final class TelegramNotificationService
{
    public function send(int $userId, string $content): void
    {
        $message = new UserMessages();
        $message->user_id = $userId;
        $message->content = $content;
        $message->status = 'send';
        $message->created_at = date('Y-m-d H:i:s');
        $message->save();
    }
}
