<?php
declare(strict_types=1);

$base = dirname(__DIR__, 2).'/app/';
foreach (['ValueObject','Identifier','OrganizationId'] as $name) {
    require_once $base.'Kernel/Shared/Domain/'.$name.'.php';
}
foreach (['Channel','DeliveryStatus','Recipient','Notification','Delivery','Template','RenderedNotification'] as $name) {
    require_once $base.'Platform/Notification/Model/'.$name.'.php';
}
foreach (['NotificationDispatcherInterface','TemplateRepositoryInterface','TemplateRendererInterface'] as $name) {
    require_once $base.'Platform/Notification/Contract/'.$name.'.php';
}
require_once $base.'Infrastructure/Platform/Notification/BuiltinNotificationTemplateRepository.php';
require_once $base.'Infrastructure/Platform/Notification/SimpleNotificationTemplateRenderer.php';
$directory = $base.'Domains/CapitalMarkets/Infrastructure/MarketData/L2/';
foreach (['L2AlertDelivery','L2AlertLifecycle'] as $name) require_once $directory.$name.'.php';

use Domains\CapitalMarkets\Infrastructure\MarketData\L2\{L2AlertDelivery,L2AlertLifecycle};
use Infrastructure\Platform\Notification\{BuiltinNotificationTemplateRepository,SimpleNotificationTemplateRenderer};
use Platform\Notification\Contract\NotificationDispatcherInterface;
use Platform\Notification\Model\{Channel,Delivery,DeliveryStatus,Notification};


final class FixtureL2AlertDispatcher implements NotificationDispatcherInterface
{
    /** @var list<Notification> */
    public array $sent = [];
    public int $failuresRemaining = 0;
    public function send(Notification $notification): Delivery
    {
        if ($this->failuresRemaining > 0) {
            --$this->failuresRemaining;
            throw new RuntimeException('Temporary notification storage error.');
        }
        $this->sent[] = $notification;
        return new Delivery(
            'delivery-'.$notification->id, $notification->id, Channel::EMAIL,
            $notification->recipient->address, DeliveryStatus::QUEUED, 0,
            new DateTimeImmutable(), null, 'test-outbox',
        );
    }
}

$templates = new BuiltinNotificationTemplateRepository();
$template = $templates->find('capital_markets.l2.alert', Channel::EMAIL, 'uk');
if ($template === null || $templates->find('capital_markets.l2.alert',Channel::TELEGRAM,'uk') !== null) {
    throw new RuntimeException('L2 email template routing regression.');
}

$dispatch = new FixtureL2AlertDispatcher();
$gateway = new L2AlertDelivery($dispatch);
$lifecycle = new L2AlertLifecycle(
    $gateway, 'org-test', 'ops@example.test', 'source:btc-l2',
    'BINANCE_SPOT', 'BTCUSDT', 'uk', null, 'fixed-run-token',
);
$lifecycle->onTransition('DEGRADED','Exchange disconnected.');
$lifecycle->onTransition('DEGRADED','Exchange disconnected again.');
$lifecycle->onTransition('STALE','No verified book.');
$lifecycle->onTransition('STALE','Repeated timeout.');
$lifecycle->onTransition('EXHAUSTED','Retry budget.');
$lifecycle->onTransition('EXHAUSTED','Retry budget.');
$lifecycle->onTransition('RECOVERED','Verified ingestion resumed.');
$lifecycle->onTransition('RECOVERED','No incident.');
if (count($dispatch->sent) !== 4 || $lifecycle->pendingCount() !== 0) {
    throw new RuntimeException('L2 lifecycle must deduplicate same-incident transitions.');
}
$transitions = array_map(static fn(Notification $n):string => $n->metadata['transition'], $dispatch->sent);
if ($transitions !== ['DEGRADED','STALE','EXHAUSTED','RECOVERED']) {
    throw new RuntimeException('L2 alert transition sequence regression.');
}
$first = $dispatch->sent[0];
if ($first->templateKey !== 'capital_markets.l2.alert' || $first->recipient->address !== 'ops@example.test'
) {
    throw new RuntimeException('L2 alert notification identity regression.');
}
if (($first->metadata['market_source_id'] ?? null) !== 'source:btc-l2') {
    throw new RuntimeException('L2 alert source association regression.');
}
$rendered = (new SimpleNotificationTemplateRenderer())->render($template,$first->variables);
if (!str_contains((string)$rendered->subject, 'BINANCE_SPOT BTCUSDT')
    || !str_contains($rendered->body, 'Exchange disconnected.')
    || !str_contains($rendered->body, 'Incident:')) {
    throw new RuntimeException('L2 operator email payload regression.');
}
$lifecycle->onTransition('DEGRADED','Second incident.');
if (count($dispatch->sent) !== 5 || $dispatch->sent[4]->id === $first->id) {
    throw new RuntimeException('L2 new incident was suppressed by dedupe.');
}

$transient = new FixtureL2AlertDispatcher();
$transient->failuresRemaining = 1;
$failures = [];
$retry = new L2AlertLifecycle(
    new L2AlertDelivery($transient), 'org-test', 'ops@example.test', 'source:btc-l2',
    'BYBIT_SPOT', 'BTCUSDT', 'uk',
    static function(Throwable $error) use (&$failures): void {$failures[]=$error->getMessage();},
    'retry-run-token',
);
$retry->onTransition('DEGRADED','connection failed');
if ($retry->pendingCount() !== 1 || count($failures) !== 1 || $transient->sent !== []) {
    throw new RuntimeException('Failed L2 alert enqueue should remain pending.');
}
$retry->onTransition('DEGRADED','retry duplicate');
if ($retry->pendingCount() !== 0 || count($transient->sent) !== 1 || count($failures) !== 1) {
    throw new RuntimeException('L2 alert retry should preserve dedupe and recover.');
}
$invalid = false;
try {
    $gateway->queue('org-test', 'not-an-email', 'source:btc-l2','BINANCE_SPOT','BTCUSDT',
        'l2-inc','DEGRADED','failure');
} catch (DomainException) {$invalid = true;}
if (!$invalid) throw new RuntimeException('Invalid L2 alert recipient accepted.');
echo "L2 alert delivery smoke passed\n";
