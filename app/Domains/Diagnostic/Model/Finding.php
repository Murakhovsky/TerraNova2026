<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use InvalidArgumentException;
final readonly class Finding
{
    /** @param list<string> $relatedCriteria @param list<string> $relatedFacts @param list<string> $evidenceRefs */
    public function __construct(public string $id, public string $type, public string $severity, public array $relatedCriteria, public array $relatedFacts, public array $evidenceRefs, public float $confidence, public string $description)
    { if ($id==='' || $type==='' || $description==='' || $confidence<0 || $confidence>1) throw new InvalidArgumentException('Invalid finding.'); }
}
