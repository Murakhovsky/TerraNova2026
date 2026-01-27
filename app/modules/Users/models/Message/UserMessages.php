<?php
namespace Modules\Users\Models\Message;

use Phalcon\Mvc\Model;

class UserMessages extends Model
{
    public int $id;
    public int $user_id;
    public int $system_message_id;
    public string $status; // send, read
    public string $created_at;
    public ?string $read_at;

    public function initialize()
    {
        $this->setSource('msg_user_messages');

        $this->belongsTo('system_message_id', SystemMessages::class, 'id', ['alias' => 'message']);
    }

    public static function countByUser($user_id)
    {
        return self::count([
            'conditions' => 'user_id = :user_id:',
            'bind'       => ['user_id' => $user_id],
        ]);
    }
}
