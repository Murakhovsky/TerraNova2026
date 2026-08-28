<?php

namespace Infrastructure\Persistence\Phalcon\Telegram\Service;

use Infrastructure\Persistence\Phalcon\Telegram\Featuring\BaseModel;

class Reminders extends BaseModel
{
    public $sender_id;
    public $receiver;
    public $message;
    public $buttons;
    public $status;
    public $period;
    public $subject_id;
    public $subject_type;
    public $reminder_at;
    public $task;

    public function initialize()
    {
        $this->setSource('service_reminders');
    }

}

