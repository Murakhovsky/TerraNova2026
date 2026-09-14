<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Model;
use DateTimeImmutable;
use InvalidArgumentException;
final readonly class FactRevision
{
    /** @param list<string> $evidenceIds */
    public function __construct(public string $factId, public int $revision, public mixed $previousValue, public mixed $newValue, public string $reason, public string $source, public array $evidenceIds, public DateTimeImmutable $createdAt)
    { if ($factId==='' || $revision<1 || trim($reason)==='' || trim($source)==='' || count(array_unique($evidenceIds))!==count($evidenceIds)) throw new InvalidArgumentException('Invalid fact revision.'); }
}
