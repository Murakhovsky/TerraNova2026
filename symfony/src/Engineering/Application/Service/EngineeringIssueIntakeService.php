<?php
declare(strict_types=1);

namespace App\Engineering\Application\Service;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Repository\EngineeringRepositoryGatewayInterface;
use App\Engineering\Domain\Workflow\EngineeringId;
use RuntimeException;

final readonly class EngineeringIssueIntakeService
{
    public function __construct(
        private EngineeringRepositoryGatewayInterface $repository,
        private EngineeringOrchestrator $engineering,
    ) {}

    public function import(
        int $issueNumber,
        bool $start,
        string $organizationId,
        string $correlationId,
    ): array {
        if (!$this->repository->available()) {
            throw new RuntimeException('Engineering GitHub repository gateway is not configured.');
        }

        $issue = $this->repository->issue($issueNumber);
        if ($issue['is_pull_request']) throw new RuntimeException('GitHub pull requests cannot be imported as Engineering feature requests.');
        if ($issue['state'] !== 'open') throw new RuntimeException('Only open GitHub issues can be imported.');

        $priority = 'P2';
        foreach ($issue['labels'] as $label) {
            $candidate = strtoupper(trim($label));
            if (in_array($candidate, ['P0','P1','P2','P3'], true)) {
                $priority = $candidate;
                break;
            }
        }

        $description = trim($issue['body']) !== '' ? $issue['body'] : $issue['title'];
        $featureId = $this->engineering->create(new EngineeringRequest(
            requestId: 'github-issue-'.$issueNumber.'-'.EngineeringId::generate(),
            description: $description,
            title: $issue['title'],
            sourceType: 'github_issue',
            sourceReference: (string) $issueNumber,
            priority: $priority,
            metadata: [
                'github_issue_url' => $issue['url'],
                'github_labels' => $issue['labels'],
            ],
        ), $organizationId, 'github-issue');

        $this->repository->addIssueLabels($issueNumber, ['cos:engineering']);
        $this->repository->commentIssue(
            $issueNumber,
            "COS Engineering feature created: `".$featureId."`."
            .($start ? " Autonomous workflow is starting." : " Workflow has not been started yet."),
        );

        $result = [
            'feature_id' => $featureId,
            'issue' => $issueNumber,
            'started' => false,
            'state' => 'NEW',
        ];

        if ($start) {
            $started = $this->engineering->start($featureId, $organizationId, $correlationId);
            $result['started'] = true;
            $result['state'] = $started->state;
            $result['workflow_id'] = $started->workflowId;
            $result['next'] = $started->next->type->value;

            $this->repository->commentIssue(
                $issueNumber,
                "COS Engineering workflow `".$started->workflowId."` reached state `".$started->state."`."
                ." Next: `".$started->next->type->value."`.",
            );
        }

        return $result;
    }
}
