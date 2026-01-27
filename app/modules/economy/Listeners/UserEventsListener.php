<?php
namespace Modules\Economy\Listeners;

use Modules\Economy\Models\Wallets;
use Modules\TgAdmin\Renderer\Messages;
use Modules\Users\Models\Person\AppUsers;

class UserEventsListener
{

    public function newProfileSaved($service, $user, $data):void
    {
        $coins = 10;
        Wallets::addCoins($user->id, $coins);
    }
    public function registered($service, $user, $data):void
    {
    }
    public function profileCompleted($service, $user, $data):void
    {
    }
    public function login($service, $user, $data):void
    {
    }
    public function loginDaily($service, $user, $data):void
    {
    }
    public function logout($service, $user, $data):void
    {
    }
    public function levelUp($service, $user, $data):void
    {
    }
    public function statusChanged($service, $user, $data):void
    {
    }
    public function referralJoined($service, $user, $data):void
    {
    }
    public function referralActivated($service, $user, $data):void
    {
    }

}
