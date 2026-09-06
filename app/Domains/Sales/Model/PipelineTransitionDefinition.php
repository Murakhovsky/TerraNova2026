<?php
declare(strict_types=1);
namespace Domains\Sales\Model;
use InvalidArgumentException;
final readonly class PipelineTransitionDefinition
{
    public function __construct(public string $id,public string $pipelineId,public string $fromStageId,public string $toStageId,public array $conditions=[],public bool $requiresApproval=false)
    { if($id===''||$pipelineId===''||$fromStageId===''||$toStageId==='')throw new InvalidArgumentException('Pipeline transition identity is required.'); }
}
