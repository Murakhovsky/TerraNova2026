<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
// The root autoloader does not provide the Symfony App\\ namespace.
require dirname(__DIR__, 2).'/symfony/src/Web/Experience/Component/CosRealtimeSubscription.php';

use App\Web\Experience\Component\CosRealtimeSubscription;

$assert = static function (bool $value, string $description): void {
    if (!$value) throw new RuntimeException($description);
};

$https = 'https://company-os.shop';
$assert(CosRealtimeSubscription::sameOrigin('https://company-os.shop/.well-known/mercure', $https),
    'Same-origin HTTPS Mercure must permit the private subscriber cookie.');
$assert(CosRealtimeSubscription::sameOrigin('https://company-os.shop:443/.well-known/mercure', $https),
    'Explicit standard port is the same browser origin.');
$assert(!CosRealtimeSubscription::sameOrigin('http://127.0.0.1:8081/.well-known/mercure', $https),
    'The production incident: a 127.0.0.1 public hub may not mint a cookie on company-os.shop.');
$assert(!CosRealtimeSubscription::sameOrigin('https://attacker.example/.well-known/mercure', $https),
    'A separate domain must fail closed.');
$assert(!CosRealtimeSubscription::sameOrigin('https://hub.company-os.shop/.well-known/mercure', $https),
    'The COS same-origin proxy contract does not permit a different subdomain.');
$assert(!CosRealtimeSubscription::sameOrigin('http://company-os.shop/.well-known/mercure', $https),
    'An HTTPS request must not accept a mixed-content HTTP hub.');
$assert(!CosRealtimeSubscription::sameOrigin('https://company-os.shop:8443/.well-known/mercure', $https),
    'Different port is cross-origin.');
$assert(!CosRealtimeSubscription::sameOrigin('//company-os.shop/.well-known/mercure', $https),
    'Schemeless hub URLs must fail closed.');
$assert(!CosRealtimeSubscription::sameOrigin('', $https),
    'Empty hub URL must fail closed.');
$assert(!CosRealtimeSubscription::sameOrigin('https://company-os.shop/.well-known/mercure', null),
    'A missing HTTP request origin must not mint a cookie.');
$template = (string)file_get_contents(dirname(__DIR__, 2).'/symfony/templates/components/experience/cos_realtime_subscription.html.twig');
$guard = strpos($template, '{% if this.canSubscribe %}');
$listen = strpos($template, 'turbo_stream_listen');
$assert($guard !== false && $listen !== false && $guard < $listen,
    'The private subscriber cookie may only be rendered inside the same-origin guard.');
$component = (string)file_get_contents(dirname(__DIR__, 2).'/symfony/src/Web/Experience/Component/CosRealtimeSubscription.php');
$assert(str_contains($component, '$this->requests->getCurrentRequest()?->getSchemeAndHttpHost()'),
    'The component must validate against the current browser-facing Symfony request origin.');

echo "Realtime hub origin regression passed.\n";
