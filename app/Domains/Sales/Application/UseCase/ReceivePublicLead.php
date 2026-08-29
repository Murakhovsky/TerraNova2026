<?php
declare(strict_types=1);

namespace Domains\Sales\Application\UseCase;

use Domains\Sales\Application\Contract\InboundCaseResolverInterface;
use Domains\Sales\Application\Contract\InboundLeadRepositoryInterface;
use Domains\Sales\Application\DTO\PublicLeadResult;
use Domains\Sales\Automation\Event\LeadCreated;
use Domains\Sales\Model\LeadStatus;
use Kernel\Event\EventBus;
use Kernel\Event\EventMetadata;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ReceivePublicLead
{
    public function __construct(
        private InboundLeadRepositoryInterface $leads,
        private InboundCaseResolverInterface $cases,
        private EventBus $events,
        private TransactionManagerInterface $transactions,
        private string $organizationId,
    ) {
    }

    public function execute(array $input, string $sourcePage): PublicLeadResult
    {
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return PublicLeadResult::accepted();
        }

        $name = trim((string) ($input['full_name'] ?? $input['name'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        if ($name === '' || ($phone === '' && $email === '')) {
            return PublicLeadResult::rejected('contact_required');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return PublicLeadResult::rejected('invalid_email');
        }

        $propertyId = $this->cases->resolvePropertyId($input['property_id'] ?? null);
        if ($propertyId !== null) $input['property_id'] = $propertyId;
        else unset($input['property_id']);

        $leadId = $this->transactions->transactional(function () use ($input, $sourcePage, $name, $phone, $email): int {
            $case = $this->cases->resolvePersonAndCase($input);
            $leadId = $this->leads->create($this->organizationId, [
                'person_id' => $case['person_id'],
                'client_case_id' => $case['client_case_id'],
                'property_id' => $this->positiveInt($input['property_id'] ?? null),
                'full_name' => mb_substr($name, 0, 160),
                'phone' => $phone !== '' ? mb_substr($phone, 0, 50) : null,
                'email' => $email !== '' ? mb_substr($email, 0, 160) : null,
                'role' => $this->role((string) ($input['role'] ?? 'buyer')),
                'deal_type' => $this->dealType((string) ($input['deal_type'] ?? $input['request_type'] ?? 'consultation')),
                'message' => ($message = trim((string) ($input['message'] ?? $input['comment'] ?? ''))) !== '' ? mb_substr($message, 0, 4000) : null,
                'source_page' => mb_substr($sourcePage, 0, 255),
            ]);

            if ($case['client_case_id'] !== null) {
                $this->cases->attachRequest($case['client_case_id'], $leadId);
            }
            $this->events->publish(LeadCreated::create(
                bin2hex(random_bytes(16)), $this->organizationId, (string) $leadId,
                [
                    'client_case_id' => $case['client_case_id'],
                    'source_page' => $sourcePage,
                    'status' => LeadStatus::New->value,
                ],
                new EventMetadata(bin2hex(random_bytes(16)), null, 'SYSTEM', 'public-web'),
            ));

            return $leadId;
        });

        return PublicLeadResult::accepted($leadId);
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function role(string $value): string
    {
        return ['buyer'=>'buyer','покупець'=>'buyer','owner'=>'seller','seller'=>'seller','власник'=>'seller',
            'investor'=>'investor','інвестор'=>'investor','realtor'=>'realtor','рієлтор'=>'realtor',
            'developer'=>'developer','забудовник'=>'developer','partner'=>'partner','партнер'=>'partner']
            [mb_strtolower(trim($value))] ?? 'other';
    }

    private function dealType(string $value): string
    {
        return ['sale'=>'sale','купівля'=>'sale','продаж'=>'sale','rent'=>'rent','оренда'=>'rent',
            'investment'=>'investment','інвестиції'=>'investment','заявка інвестора'=>'investment',
            'consultation'=>'consultation','партнерство'=>'consultation'][mb_strtolower(trim($value))] ?? 'consultation';
    }
}
