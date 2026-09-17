<?php
declare(strict_types=1);

namespace Platform\Notification\Model;

enum Channel: string
{
    case EMAIL = 'email';
    case TELEGRAM = 'telegram';
    case PUSH = 'push';
    case SMS = 'sms';
    case WEBSOCKET = 'websocket';
}
