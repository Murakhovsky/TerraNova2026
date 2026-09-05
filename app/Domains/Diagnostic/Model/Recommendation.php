<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use DomainException;
use Domains\Diagnostic\Report\RecommendationStatus;
use InvalidArgumentException;
final class Recommendation
{
    /** @param list<string> $relatedFindings @param list<string> $relatedRootCauses @param list<string> $actions @param list<string> $successMetrics */
    public function __construct(public readonly string $id, public readonly array $relatedFindings, public readonly array $relatedRootCauses, public readonly string $priority, public readonly string $impact, public readonly string $effort, public readonly string $rationale, public readonly array $actions, public readonly array $successMetrics, public RecommendationStatus $status=RecommendationStatus::Proposed, public readonly float $priorityScore=0.0, public readonly array $details=[])
    { if ($id==='' || ($relatedFindings===[] && $relatedRootCauses===[]) || $rationale==='' || $actions===[] || $successMetrics===[]) throw new InvalidArgumentException('Invalid recommendation.'); }
    public function transitionTo(RecommendationStatus $next):void
    {
        $allowed=match($this->status){RecommendationStatus::Proposed=>[RecommendationStatus::Accepted,RecommendationStatus::Rejected],RecommendationStatus::Accepted=>[RecommendationStatus::Planned],RecommendationStatus::Planned=>[RecommendationStatus::InProgress],RecommendationStatus::InProgress=>[RecommendationStatus::Implemented],RecommendationStatus::Implemented=>[RecommendationStatus::Measured],RecommendationStatus::Measured=>[RecommendationStatus::Successful,RecommendationStatus::Failed],default=>[]};
        if(!in_array($next,$allowed,true))throw new DomainException('Invalid recommendation lifecycle transition.');$this->status=$next;
    }
}
