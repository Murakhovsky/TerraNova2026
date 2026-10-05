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
        private PageDeliveryEvidenceBuilder $evidence,
        private PageDeliveryPullRequestTemplate $pullRequestTemplate,
        private ExperienceAutonomyPolicy $autonomy,
        private EngineeringOrchestrator $engineering,
    ) {}

    public function prepare(string $pageId): EngineeringRequest
    {
        $package = $this->context->build($pageId);
        $data = $package->toArray();
        $contract = $data['page_contract'];
        $evidence = $this->evidence->build($package);
        $pullRequestBody = $this->pullRequestTemplate->render($package, $evidence);
        $autonomy = $this->autonomy->levelFor($pageId);
        $this->autonomy->assertAllowed($autonomy);
        $risk = $this->autonomy->riskFor($pageId);

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
                'experience_evidence_contract' => $evidence->toArray(),
                'experience_pull_request_template' => $pullRequestBody,
                'experience_autonomy_level' => $autonomy->value,
                'experience_risk' => $risk,
                'experience_auto_merge' => false,
            ],
            constraints: is_array($data['agent_constraints']['rules'] ?? null) ? $data['agent_constraints']['rules'] : [],
            previousContext: [
                'page_contract' => $contract,
                'archetype' => $data['archetype'],
                'patterns' => $data['patterns'],
                'quality_gate' => $data['quality_gate'],
                'evidence_contract' => $evidence->toArray(),
                'pull_request_template' => $pullRequestBody,
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
            'autonomy' => $request->metadata['experience_autonomy_level'] ?? 'L2',
            'risk' => $request->metadata['experience_risk'] ?? 'HIGH',
            'auto_merge' => false,
        ];
    }
}
