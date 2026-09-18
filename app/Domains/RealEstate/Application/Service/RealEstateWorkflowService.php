<?php
declare(strict_types=1);

namespace Domains\RealEstate\Application\Service;

use DateTimeImmutable;
use Domains\Property\Application\Contract\PropertyInventoryCommandInterface;
use Domains\Property\Contract\PropertyReferencePort;
use Domains\RealEstate\Application\Contract\RealEstateRepositoryInterface;
use Domains\RealEstate\Application\Contract\SalesOpportunityReferenceInterface;
use Domains\RealEstate\Automation\Event\RealEstateDomainEvents;
use Domains\RealEstate\Automation\Event\RealEstateEventType;
use Domains\RealEstate\Domain\BrokerageProcess;
use Domains\RealEstate\Domain\Offer;
use Domains\RealEstate\Domain\Showing;
use InvalidArgumentException;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Shared\Domain\Money;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class RealEstateWorkflowService
{
    public function __construct(
        private RealEstateRepositoryInterface $repository,
        private SalesOpportunityReferenceInterface $sales,
        private PropertyReferencePort $properties,
        private PropertyInventoryCommandInterface $inventory,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function match(
        string $organizationId,
        int $actorId,
        int $opportunityId,
        array $input,
        string $correlationId,
    ): array {
        if (!$this->sales->exists($organizationId, $opportunityId)) {
            throw new InvalidArgumentException('Sales opportunity was not found in the current organization.');
        }

        $reference = trim((string)($input['property_id'] ?? ''));
        if ($reference === '') throw new InvalidArgumentException('property_id is required.');
        $presentation = $this->properties->getPropertyPresentation($organizationId, $reference);
        if ($presentation === null) throw new InvalidArgumentException('Property was not found.');

        $property = is_array($presentation['property'] ?? null) ? $presentation['property'] : [];
        $propertyId = trim((string)($property['asset_id'] ?? ''));
        if ($propertyId === '') throw new InvalidArgumentException('Property has no canonical asset id.');

        $existing = $this->repository->findMatch($organizationId, $opportunityId, $propertyId);
        if ($existing !== null) return $this->view($organizationId, $existing->id);

        $inventoryId = trim((string)($input['inventory_id'] ?? ($presentation['inventory']['inventory_id'] ?? '')));
        if ($inventoryId !== '') {
            $inventory = $this->properties->getInventorySnapshot($organizationId, $inventoryId);
            if ($inventory === null || (string)($inventory['asset_id'] ?? '') !== $propertyId) {
                throw new InvalidArgumentException('Inventory does not belong to the matched Property.');
            }
        }

        $case = new BrokerageProcess(
            $this->id('RE'),
            OrganizationId::fromString($organizationId),
            $opportunityId,
            $propertyId,
            $inventoryId !== '' ? $inventoryId : null,
            trim((string)($input['subject'] ?? 'Property match')),
        );
        $metadata = $this->metadata($actorId, $correlationId);

        $this->transactions->transactional(function () use ($case, $actorId, $metadata): void {
            $this->repository->saveCase($case, $actorId);
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::PROPERTY_MATCHED,
                $case->organizationId->value(),
                $case->id,
                [
                    'opportunity_id'=>$case->opportunityId,
                    'property_id'=>$case->propertyId,
                    'inventory_id'=>$case->inventoryId,
                ],
                $metadata,
            ));
        });

        return $this->view($organizationId, $case->id);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createOffer(string $organizationId, int $actorId, string $caseId, array $input, string $correlationId): array
    {
        $case = $this->case($organizationId, $caseId);
        $partyId = trim((string)($input['party_id'] ?? ''));
        if ($partyId === '') throw new InvalidArgumentException('party_id is required.');
        $amountMinor = array_key_exists('amount_minor', $input)
            ? (int)$input['amount_minor']
            : (int)round(((float)($input['amount'] ?? 0)) * 100);
        if ($amountMinor <= 0) throw new InvalidArgumentException('Offer amount must be positive.');

        $offer = new Offer(
            $this->id('OFR'),
            $case->organizationId,
            $case->propertyId,
            $partyId,
            new Money($amountMinor, strtoupper((string)($input['currency'] ?? 'USD'))),
        );
        $next = $case->transitionTo(BrokerageProcess::OFFERED);
        $metadata = $this->metadata($actorId, $correlationId);

        $this->transactions->transactional(function () use ($caseId, $offer, $next, $actorId, $metadata): void {
            $this->repository->saveOffer($caseId, $offer, $actorId);
            $this->repository->saveCase($next, $actorId);
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::OFFER_CREATED,
                $next->organizationId->value(),
                $next->id,
                [
                    'offer_id'=>$offer->id,
                    'amount_minor'=>$offer->amount->minorUnits(),
                    'currency'=>$offer->amount->currency(),
                ],
                $metadata,
            ));
        });
        return $this->view($organizationId, $caseId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function scheduleViewing(string $organizationId, int $actorId, string $caseId, array $input, string $correlationId): array
    {
        $case = $this->case($organizationId, $caseId);
        $clientId = trim((string)($input['client_id'] ?? ''));
        $raw = trim((string)($input['scheduled_at'] ?? ''));
        if ($clientId === '' || $raw === '') throw new InvalidArgumentException('client_id and scheduled_at are required.');

        $showing = new Showing($this->id('SHW'), $case->organizationId, $case->propertyId, $clientId);
        $scheduledAt = new DateTimeImmutable($raw);
        $notes = trim((string)($input['notes'] ?? '')) ?: null;
        $next = $case->transitionTo(BrokerageProcess::VIEWING);
        $metadata = $this->metadata($actorId, $correlationId);

        $this->transactions->transactional(function () use ($caseId, $showing, $scheduledAt, $notes, $next, $actorId, $metadata): void {
            $this->repository->saveShowing($caseId, $showing, $scheduledAt, $notes, $actorId);
            $this->repository->saveCase($next, $actorId);
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::VIEWING_SCHEDULED,
                $next->organizationId->value(),
                $next->id,
                ['showing_id'=>$showing->id,'scheduled_at'=>$scheduledAt->format(DATE_ATOM)],
                $metadata,
            ));
        });
        return $this->view($organizationId, $caseId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reserve(string $organizationId, int $actorId, string $caseId, array $input, string $correlationId): array
    {
        $case = $this->case($organizationId, $caseId);
        if ($case->inventoryId === null) throw new InvalidArgumentException('Matched case has no reservable inventory.');

        $metadata = $this->metadata($actorId, $correlationId);
        $result = $this->transactions->transactional(function () use ($case, $input, $actorId, $correlationId, $metadata): array {
            $reservation = $this->inventory->reserve(
                $case->organizationId->value(),
                $case->inventoryId,
                [
                    'reservation_id'=>$input['reservation_id'] ?? null,
                    'reserved_for_reference'=>'real_estate_case:' . $case->id,
                    'expires_at'=>$input['expires_at'] ?? null,
                    'reason'=>$input['reason'] ?? 'brokerage_reservation',
                ],
                (string)$actorId,
                $correlationId,
            );
            $next = $case->transitionTo(BrokerageProcess::RESERVED);
            $this->repository->saveCase($next, $actorId);
            $this->events->publish(RealEstateDomainEvents::create(
                RealEstateEventType::PROPERTY_RESERVED,
                $next->organizationId->value(),
                $next->id,
                [
                    'inventory_id'=>$next->inventoryId,
                    'reservation_id'=>$reservation['reservation_id'] ?? null,
                ],
                $metadata,
            ));
            return $reservation;
        });

        return ['case'=>$this->view($organizationId, $caseId),'reservation'=>$result];
    }

    /** @return array<string,mixed> */
    public function view(string $organizationId, string $caseId): array
    {
        return $this->repository->view($organizationId, $caseId)
            ?? throw new InvalidArgumentException('RealEstate brokerage case was not found.');
    }

    private function case(string $organizationId, string $caseId): BrokerageProcess
    {
        return $this->repository->findCase($organizationId, $caseId)
            ?? throw new InvalidArgumentException('RealEstate brokerage case was not found.');
    }

    private function metadata(int $actorId, string $correlationId): EventMetadata
    {
        return new EventMetadata($correlationId, null, 'USER', (string)$actorId);
    }

    private function id(string $prefix): string
    {
        return $prefix . '-' . strtoupper(bin2hex(random_bytes(10)));
    }
}
