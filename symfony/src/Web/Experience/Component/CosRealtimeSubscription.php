<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\Realtime\RealtimeTopic;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosRealtimeSubscription',
    template: 'components/experience/cos_realtime_subscription.html.twig',
)]
final class CosRealtimeSubscription
{
    public RealtimeTopic $topic;
    public string $transport = 'default';
}
