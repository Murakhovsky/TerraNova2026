<?php
declare(strict_types=1);

namespace App\Web\Experience\Delivery;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Service\EngineeringOrchestrator;
use App\Engineering\Domain\Workflow\EngineeringId;

final readonly class PageDeliveryWorkflow
{
    public function __construct(
        private PageDeliveryContextPackageBuilder $context,
        private EngineeringOrchestrator $engineering,
    ) {}

    public function prepare(string $pageId): EngineeringRequest
    {
        $package = $this->context->build($pageId);
        $data = $package->toArray();
        $contract = $data['page_contract'];

        return new EngineeringRequest(
            requestId: 'experience-page-'.$pageId.'-'.EngineeringId::generate(),
            title: 'Experience delivery: '.$pageId,
            description: sprintf(
                'Deliver registered COS page %s (%s) to its declared Experience contract and QA gates.',
                $pageId,
                $contract['route']['path'] ?? '',
            ),
            sourceType: 'experience_page_delivery',
            sourceReference: $pageId,
            priority: (string) ($contract['priority'] ?? 'P2'),
            metadata: [
                'experience_page_id' => $pageId,
                'experience_context_package' => $data,
            ],
            constraints: $data['agent_constraints'],
            previousContext: [
                'page_contract' => $contract,
                'archetype' => $data['archetype'],
                'patterns' => $data['patterns'],
                'quality_gate' => $data['quality_gate'],
            ],
        );
    }

    public function start(string $pageId, string $organizationId, string $correlationId): array
    {
        $request = $this->prepare($pageId);
        $featureId = $this->engineering->create($request, $organizationId, 'experience-delivery');
        $result = $this->engineering->start($featureId, $organizationId, $correlationId);

        return [
            'feature_id' => $featureId,
            'workflow_id' => $result->workflowId,
            'state' => $result->state,
            'next' => $result->next->type->value,
        ];
    }
}
