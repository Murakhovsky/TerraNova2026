<?php
declare(strict_types=1);

namespace Domains\Sales\Model;

enum CommunicationChannel: string
{
    use HasStringValues;

    case Telegram = 'TELEGRAM';
    case Email = 'EMAIL';
    case Phone = 'PHONE';
    case Web = 'WEB';
    case WhatsApp = 'WHATSAPP';
    case Viber = 'VIBER';
}
