<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Infrastructure\MarketData\L2;

use DateTimeImmutable;
use DomainException;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Notification\Contract\NotificationDispatcherInterface;
use Platform\Notification\Model\{Channel, DeliveryStatus, Notification, Recipient};

/**
 * Sends operator alerts through the existing durable Platform Notification ->
 * n8n outbox pipeline. QUEUED is acceptance, not proof of email delivery.
 */
final readonly class L2AlertDelivery
{
    public function __construct(private NotificationDispatcherInterface $notifications) {}

    public function queue(
        string $organizationId,
        string $recipientEmail,
        string $sourceId,
        string $venue,
        string $symbol,
        string $incidentId,
        string $transition,
        string $reason,
        string $locale = 'uk',
    ): void {
        if (!in_array($transition, ['DEGRADED','STALE','EXHAUSTED','RECOVERED'], true)) {
            throw new DomainException('Invalid L2 alert transition.');
        }
        if (!in_array($locale, ['uk','en'], true)) throw new DomainException('Invalid L2 alert locale.');
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) throw new DomainException('Invalid L2 alert recipient.');
        foreach ([$sourceId, $venue, $symbol, $incidentId] as $value) {
            if (trim($value) === '' || strlen($value) > 190) {
                throw new DomainException('Invalid L2 alert identity.');
            }
        }
        // No raw frames, URLs, access tokens or unbounded exception traces in an email.
        $reason = trim((string) (preg_replace('/[\x00-\x1F\x7F]+/', ' ', $reason) ?? ''));
        $reason = mb_strcut($reason, 0, 180, 'UTF-8');
        if ($reason === '') $reason = 'UNKNOWN';
        $subjects = [
            'DEGRADED' => 'L2 stream degraded',
            'STALE' => 'L2 market data stale',
            'EXHAUSTED' => 'L2 reconnect budget exhausted',
            'RECOVERED' => 'L2 stream recovered',
        ];
        $title = $subjects[$transition];
        $subject = mb_strcut('[COS L2] '.$title.' | '.$venue.' '.$symbol, 0, 240, 'UTF-8');
        $body = implode("\n", [
            'Capital Markets L2 operational alert',
            'Status: '.$transition,
            'Organization: '.$organizationId,
            'Source: '.$sourceId,
            'Venue: '.$venue,
            'Symbol: '.$symbol,
            'Incident: '.$incidentId,
            'Reason: '.$reason,
            '',
            'QUEUED means accepted into the COS delivery outbox, not delivered to the inbox.',
        ]);
        $notificationId = 'CML2-'.strtoupper(substr(hash(
            'sha256', implode('|', [$organizationId, strtolower($recipientEmail), $incidentId, $transition]),
        ), 0, 24));
        $delivery = $this->notifications->send(new Notification(
            $notificationId,
            OrganizationId::fromString($organizationId),
            Channel::EMAIL,
            'capital_markets.l2.alert',
            new Recipient($recipientEmail),
            ['subject'=>$subject,'body'=>$body],
            $locale,
            $incidentId,
            new DateTimeImmutable(),
            [
                'capital_markets_alert'=>'l2',
                'market_source_id'=>$sourceId,
                'venue'=>$venue,
                'symbol'=>$symbol,
                'incident_id'=>$incidentId,
                'transition'=>$transition,
            ],
        ));
        if ($delivery->status === DeliveryStatus::FAILED) {
            throw new DomainException('L2 alert was rejected by Platform Notification delivery.');
        }
    }
}
