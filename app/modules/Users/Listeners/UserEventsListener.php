<?php
namespace Modules\Users\Listeners;

use Modules\Users\Models\Person\AppUsers;
use Modules\Economy\Services\EconomyService;
use Modules\Users\Models\Person\UsersProgress;
use Modules\Users\Services\NotificationService;
use Phalcon\Events\Event;

class UserEventsListener
{
    public function profileCompleted(Event $event, AppUsers $user):void
    {
        var_dump("test onProfileCompleted");

        // Нараховуємо бонус
        di('economyService')->credit($user->id, 50, 'coin', 'profile_bonus');

        // Надсилаємо повідомлення
        di('notificationService')->send($user->id, 'Вітаємо! Ви отримали бонус за заповнення профілю.');
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
