<?php
namespace Modules\Users\Services;

use Modules\Users\Models\Message\UserMessages;
use Modules\Users\Models\Message\SystemMessages;

class MessageService
{
    public function sendToUser(int $userId, string $code): void
    {
        $message = SystemMessages::findFirstByCode($code);
        if (!$message) {
            throw new \Exception("System message with code '{$code}' not found.");
        }

        $userMessage = new UserMessages();
        $userMessage->user_id = $userId;
        $userMessage->system_message_id = $message->id;
        $userMessage->status = 'sent';
        $userMessage->created_at = date('Y-m-d H:i:s');
        $userMessage->save();
    }

    public function markAsRead(int $userMessageId): void
    {
        $message = UserMessages::findFirstById($userMessageId);
        if ($message && $message->status === 'sent') {
            $message->status = 'read';
            $message->read_at = date('Y-m-d H:i:s');
            $message->save();
        }
    }
}
