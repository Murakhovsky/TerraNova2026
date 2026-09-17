<?php
declare(strict_types=1);

namespace Platform\Notification\Service;

use RuntimeException;
use Platform\Notification\Contract\DeliveryRepositoryInterface;
use Platform\Notification\Contract\NotificationChannelInterface;
use Platform\Notification\Contract\NotificationDispatcherInterface;
use Platform\Notification\Contract\TemplateRendererInterface;
use Platform\Notification\Contract\TemplateRepositoryInterface;
use Platform\Notification\Model\Delivery;
use Platform\Notification\Model\Notification;

final class NotificationDispatcher implements NotificationDispatcherInterface
{
    /** @var array<string,NotificationChannelInterface> */
    private array $channels = [];

    /** @param iterable<NotificationChannelInterface> $channels */
    public function __construct(
        iterable $channels,
        private readonly TemplateRepositoryInterface $templates,
        private readonly TemplateRendererInterface $renderer,
        private readonly DeliveryRepositoryInterface $deliveries,
    ) {
        foreach ($channels as $channel) {
            $key = $channel->channel()->value;
            if (isset($this->channels[$key])) {
                throw new RuntimeException(sprintf('Notification channel %s is registered twice.', $key));
            }
            $this->channels[$key] = $channel;
        }
    }

    public function send(Notification $notification): Delivery
    {
        $adapter = $this->channels[$notification->channel->value] ?? null;
        if ($adapter === null) {
            throw new RuntimeException(sprintf('Notification channel %s is not registered.', $notification->channel->value));
        }

        $template = $this->templates->find($notification->templateKey, $notification->channel, $notification->locale);
        if ($template === null) {
            throw new RuntimeException(sprintf('Notification template %s/%s/%s was not found.', $notification->templateKey, $notification->channel->value, $notification->locale));
        }

        $rendered = $this->renderer->render($template, $notification->variables);
        $delivery = $adapter->deliver($notification, $rendered);
        $this->deliveries->save($delivery);

        return $delivery;
    }
}
