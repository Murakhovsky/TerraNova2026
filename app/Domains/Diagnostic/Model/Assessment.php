<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use InvalidArgumentException;
final readonly class Assessment
{
    /** @param list<string> $evidenceRefs */
    public function __construct(public string $criterionId, public AssessmentStatus $status, public ?float $score, public float $confidence, public array $evidenceRefs, public string $reason)
    {
        if ($criterionId==='' || $reason==='' || $confidence<0 || $confidence>1 || ($score!==null && ($score<0 || $score>100))) throw new InvalidArgumentException('Invalid assessment.');
        if (in_array($status,[AssessmentStatus::Unknown,AssessmentStatus::NotApplicable],true) && $score!==null) throw new InvalidArgumentException('Unknown/not-applicable assessment cannot have a score.');
    }
}
