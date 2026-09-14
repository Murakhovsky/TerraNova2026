<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Evaluation;
use DateTimeImmutable;
use InvalidArgumentException;
final readonly class ReDiagnosticSchedule { public DateTimeImmutable $dueAt; public function __construct(public string $previousDiagnosticId,DateTimeImmutable $completedAt,public int $intervalDays){if(!in_array($intervalDays,[30,60,90],true))throw new InvalidArgumentException('Re-diagnostic interval must be 30, 60 or 90 days.');$this->dueAt=$completedAt->modify('+'.$intervalDays.' days');} }
