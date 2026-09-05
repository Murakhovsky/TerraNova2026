<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use InvalidArgumentException;
final readonly class Recommendation
{
    /** @param list<string> $relatedFindings @param list<string> $relatedRootCauses @param list<string> $actions @param list<string> $successMetrics */
    public function __construct(public string $id, public array $relatedFindings, public array $relatedRootCauses, public string $priority, public string $impact, public string $effort, public string $rationale, public array $actions, public array $successMetrics)
    { if ($id==='' || ($relatedFindings===[] && $relatedRootCauses===[]) || $rationale==='' || $actions===[] || $successMetrics===[]) throw new InvalidArgumentException('Invalid recommendation.'); }
}
