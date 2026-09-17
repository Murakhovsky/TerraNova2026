<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Shared\Domain\OrganizationId;
use Platform\Notification\Application\Command\SendNotificationCommand;
use Platform\Notification\Application\Command\SendNotificationHandler;
use Platform\Notification\Contract\DeliveryRepositoryInterface;
use Platform\Notification\Contract\NotificationChannelInterface;
use Platform\Notification\Contract\TemplateRendererInterface;
use Platform\Notification\Contract\TemplateRepositoryInterface;
use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Delivery;
use Platform\Notification\Model\DeliveryStatus;
use Platform\Notification\Model\Notification;
use Platform\Notification\Model\Recipient;
use Platform\Notification\Model\RenderedNotification;
use Platform\Notification\Model\Template;
use Platform\Notification\Service\NotificationDispatcher;

$template = new Template('tpl-1', 'deal.assigned', Channel::TELEGRAM, 'uk', null, 'Deal {{id}} assigned');
$templates = new class($template) implements TemplateRepositoryInterface {
    public function __construct(private Template $template) {}
    public function find(string $key, Channel $channel, string $locale): ?Template { return $key === $this->template->key && $channel === $this->template->channel ? $this->template : null; }
};
$renderer = new class implements TemplateRendererInterface {
    public function render(Template $template, array $variables): RenderedNotification {
        $body = $template->body;
        foreach ($variables as $key => $value) $body = str_replace('{{'.$key.'}}', (string)$value, $body);
        return new RenderedNotification($template->subject, $body);
    }
};
$channel = new class implements NotificationChannelInterface {
    public function channel(): Channel { return Channel::TELEGRAM; }
    public function deliver(Notification $notification, RenderedNotification $rendered): Delivery {
        assert($rendered->body === 'Deal 42 assigned');
        return new Delivery('del-1', $notification->id, $notification->channel, $notification->recipient->address, DeliveryStatus::SENT, 1, new DateTimeImmutable(), new DateTimeImmutable(), 'provider-1');
    }
};
$deliveries = new class implements DeliveryRepositoryInterface {
    public array $items = [];
    public function save(Delivery $delivery): void { $this->items[] = $delivery; }
};
$dispatcher = new NotificationDispatcher([$channel], $templates, $renderer, $deliveries);
$notification = new Notification('n-1', OrganizationId::fromString('org-1'), Channel::TELEGRAM, 'deal.assigned', new Recipient('chat-123', 'Owner'), ['id' => 42], 'uk', 'corr-1', new DateTimeImmutable());
$handler = new SendNotificationHandler($dispatcher);
$delivery = $handler(new SendNotificationCommand($notification));
assert($delivery->status === DeliveryStatus::SENT);
assert(count($deliveries->items) === 1);

echo "Platform Notification foundation OK\n";
