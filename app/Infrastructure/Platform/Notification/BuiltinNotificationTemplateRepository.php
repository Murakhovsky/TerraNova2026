<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Notification;

use Platform\Notification\Contract\TemplateRepositoryInterface;
use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Template;

final class BuiltinNotificationTemplateRepository implements TemplateRepositoryInterface
{
    public const GROWTH_OUTBOUND_MESSAGE='growth.outbound.message';

    public function find(string $key,Channel $channel,string $locale):?Template
    {
        if($key!==self::GROWTH_OUTBOUND_MESSAGE||$channel!==Channel::EMAIL)return null;

        return new Template(
            'builtin-growth-outbound-email-v1',
            self::GROWTH_OUTBOUND_MESSAGE,
            Channel::EMAIL,
            trim($locale)!==''?trim($locale):'en',
            '{{subject}}',
            '{{body}}',
        );
    }
}
