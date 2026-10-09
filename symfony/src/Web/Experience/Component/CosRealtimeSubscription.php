<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\Realtime\RealtimeTopic;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosRealtimeSubscription',
    template: 'components/experience/cos_realtime_subscription.html.twig',
)]
final class CosRealtimeSubscription
{
    public RealtimeTopic $topic;
    public string $transport = 'default';
    public string $signalTarget = '';

    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire('%env(MERCURE_PUBLIC_URL)%')]
        private readonly string $publicHubUrl,
    ) {}

    /**
     * A private Mercure subscriber cookie can only be issued for a compatible
     * browser origin. COS uses same-origin Mercure through the nginx SSE proxy.
     * Never let a misconfigured public hub URL break an unrelated SSR page.
     */
    public function getCanSubscribe(): bool
    {
        return self::sameOrigin($this->publicHubUrl, $this->requests->getCurrentRequest()?->getSchemeAndHttpHost());
    }

    public static function sameOrigin(string $publicHubUrl, ?string $webOrigin): bool
    {
        if ($webOrigin === null || $webOrigin === '') {
            return false;
        }

        $parts = parse_url($publicHubUrl);
        $web = parse_url($webOrigin);
        if (!is_array($parts) || !is_array($web)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
            return false;
        }

        $pageScheme = strtolower((string)($web['scheme'] ?? ''));
        $pageHost = strtolower((string)($web['host'] ?? ''));
        if (!in_array($pageScheme, ['http', 'https'], true) || $pageHost === '') {
            return false;
        }

        $hubPort = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $pagePort = (int)($web['port'] ?? ($pageScheme === 'https' ? 443 : 80));

        return $scheme === $pageScheme
            && $host === $pageHost
            && $hubPort === $pagePort;
    }
}
