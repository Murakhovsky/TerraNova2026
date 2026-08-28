<?php
namespace Interfaces\Telegram\Listener;

use Infrastructure\Persistence\Phalcon\Identity\Telegram\Person\AppUsers;
use Infrastructure\Persistence\Phalcon\Identity\Telegram\Person\UsersProgress;
use Phalcon\Events\Event;

class IdentityUserEventsListener
{
    public function profileCompleted(Event $event, AppUsers $user):void
    {
        di('notificationService')->send($user->id, 'Вітаємо! Профіль успішно заповнено.');
    }

    public function newProfileSaved(Event $event, AppUsers $user):void
    {
        $xp = 10;
        UsersProgress::addXP($user->id, $xp);
    }

    public function editedProfileSaved($user)
    {

    }
}


