<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\Realtime\RealtimeTopic;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
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
        return self::sameOrigin($this->publicHubUrl, $this->requests->getCurrentRequest());
    }

    public static function sameOrigin(string $publicHubUrl, ?Request $request): bool
    {
        if ($request === null) {
            return false;
        }

        $parts = parse_url($publicHubUrl);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['https', 'http'], true) || $host === '') {
            return false;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $hubPort = (int)($parts['port'] ?? $defaultPort);

        return $scheme === strtolower($request->getScheme())
            && $host === strtolower($request->getHost())
            && $hubPort === $request->getPort();
    }
}
