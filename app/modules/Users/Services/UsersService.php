<?php
namespace Modules\Users\Services;

use Modules\TgAdmin\Renderer\Messages;
use Modules\Users\Models\Message\UserMessages;
use Modules\Users\Models\Message\SystemMessages;
use Modules\Users\Models\Person\AppUsers;
use Modules\Users\Models\Person\UsersProgress;

class UsersService
{
    private AppUsers $user;


    public function __construct()
    {
        $this->user = new AppUsers();
    }
    public static function getUserProgress(int $user_id):UsersProgress
    {
        return UsersProgress::findFirstOrCreate($user_id);
//        return array(
//            "level" => $progress->level,
//            "xp" => $progress->xp,
//            "rating" => $progress->rating,
//        );
    }
    public static function getUserMessages(int $user_id)
    {
        $messages = UserMessages::find("user_id = '$user_id' AND status = 'sent'");
 //        $count = UserMessages::countByUser($user_id);
        return $messages;
    }

    public static function getUser(int $user_id):AppUsers
    {
        return AppUsers::findFirstOrCreate($user_id);
    }
    public function findByID($id)
    {
//        $this->user = AppUsers::findFirst($id);
        return AppUsers::findFirst($id);
    }
    public function assign($data):void
    {
        $this->user->assign($data);
    }

    public function save():?int
    {
       return $this->user->saveModel();
    }

}
