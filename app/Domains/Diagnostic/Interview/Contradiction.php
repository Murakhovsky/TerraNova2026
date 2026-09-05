<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Interview;
final readonly class Contradiction { public function __construct(public string $id,public string $statementA,public string $statementB,public array $evidenceA,public array $evidenceB,public string $severity,public string $status='OPEN',public string $requiredClarification=''){if(!in_array($status,['OPEN','RESOLVED','ACCEPTED_VARIANCE','DATA_QUALITY_ISSUE'],true))throw new \InvalidArgumentException('Invalid contradiction status.');} }
