<?php
namespace Modules\Users\Models\Message;

use Phalcon\Mvc\Model;

class SystemMessages extends Model
{
    public int $id;
    public string $code;
    public string $title;
    public string $content;
    public string $type;
    public string $created_at;

    public function initialize()
    {
        $this->setSource('msg_system_messages');
    }
}
