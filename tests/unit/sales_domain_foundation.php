<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Domains\Sales\Domain\Activity\ActivityType;
use Domains\Sales\Domain\Contact\Contact;
use Domains\Sales\Domain\Contact\ContactId;
use Domains\Sales\Domain\Lead\Lead;
use Domains\Sales\Domain\Lead\LeadId;
use Domains\Sales\Domain\Lead\LeadStatus;
use Domains\Sales\Domain\Opportunity\OpportunityStatus;
use Domains\Sales\Domain\Pipeline\PipelineId;
use Kernel\Shared\Domain\OrganizationId;

function expectSalesDomain(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$organizationId = OrganizationId::fromString('org-1');
$contact = new Contact(ContactId::fromString('contact-1'), $organizationId, 'Ada Lovelace', 'ada@example.test');
$lead = new Lead(LeadId::fromString('lead-1'), $organizationId, LeadStatus::New, $contact->id, source: 'website');

expectSalesDomain($lead->withStatus(LeadStatus::Qualified)->status === LeadStatus::Qualified, 'Lead status transition must return canonical state.');
expectSalesDomain(LeadStatus::Negotiation->value === 'negotiation', 'Canonical Lead negotiation state must remain stable.');
expectSalesDomain(OpportunityStatus::Lost->value === 'lost', 'Canonical Opportunity lost state must remain stable.');
expectSalesDomain(ActivityType::Call->value === 'call', 'Canonical activity type must remain stable.');
expectSalesDomain(class_exists(PipelineId::class), 'Canonical Pipeline id must autoload.');

echo "Sales Domain foundation contract passed without legacy mappers.\n";
