<?php
declare(strict_types=1);

namespace Infrastructure\Platform\Notification;

use Platform\Notification\Contract\TemplateRepositoryInterface;
use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Template;

final class BuiltinNotificationTemplateRepository implements TemplateRepositoryInterface
{
    public const GROWTH_OUTBOUND_MESSAGE='growth.outbound.message';
    public const GROWTH_COLLECTOR_INCIDENT='growth.collector.incident';
    public const CAPITAL_MARKETS_L2_ALERT='capital_markets.l2.alert';

    public function find(string $key,Channel $channel,string $locale):?Template
    {
        if($channel!==Channel::EMAIL)return null;

        return match($key){
            self::GROWTH_OUTBOUND_MESSAGE=>new Template(
                'builtin-growth-outbound-email-v1',
                self::GROWTH_OUTBOUND_MESSAGE,
                Channel::EMAIL,
                trim($locale)!==''?trim($locale):'en',
                '{{subject}}',
                '{{body}}',
            ),
            self::GROWTH_COLLECTOR_INCIDENT=>new Template(
                'builtin-growth-collector-incident-email-v1',
                self::GROWTH_COLLECTOR_INCIDENT,
                Channel::EMAIL,
                trim($locale)!==''?trim($locale):'en',
                '{{subject}}',
                '{{body}}',
            ),
            self::CAPITAL_MARKETS_L2_ALERT=>new Template(
                'builtin-capital-markets-l2-alert-email-v1',
                self::CAPITAL_MARKETS_L2_ALERT,
                Channel::EMAIL,
                trim($locale)!==''?trim($locale):'en',
                '{{subject}}',
                '{{body}}',
            ),
            default=>null,
        };
    }
}
