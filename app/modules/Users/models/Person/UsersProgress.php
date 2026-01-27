<?php


namespace Modules\Users\Models\Person;

use Phalcon\Mvc\Model;

class UsersProgress extends Model
{
    public $user_id;
    public int $level;
    public int $xp;
    public $rating;
    public $updated_at;

    public function initialize()
    {
        $this->setSource('person_users_progress');
    }

    public static function findFirstOrCreate(int $userId): UsersProgress
    {
        $progress = self::findFirst([
            'conditions' => 'user_id = :user_id:',
            'bind' => ['user_id' => $userId]
        ]);

        if (!$progress) {
            $progress = new UsersProgress();
            $progress->user_id = $userId;
            $progress->level = 0;
            $progress->xp = 0;
            $progress->rating = 0;
            $progress->save();
        }
        return $progress;
    }

    public static function addXP(int $user_id, int $xp):void
    {
        $progress = self::findFirstOrCreate($user_id);
        $progress->xp += $xp;
        $progress->save();

        di('eventService')->fire("user:xpAdded", $progress, ["user_id" => $user_id, "xp" => $xp]);
    }
}