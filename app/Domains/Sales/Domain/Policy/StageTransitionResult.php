<?php
declare(strict_types=1);
namespace Domains\Sales\Domain\Policy;
use Domains\Sales\Model\PipelineTransitionDefinition;
final readonly class StageTransitionResult { private function __construct(public bool $allowed,public string $reason,public ?PipelineTransitionDefinition $transition,public bool $requiresApproval,public bool $noOp=false){} public static function allowed(?PipelineTransitionDefinition $t,bool $noOp=false):self{return new self(true,$noOp?'Stage is already current.':'Transition is allowed.',$t,$t?->requiresApproval??false,$noOp);}public static function denied(string $reason):self{return new self(false,$reason,null,false);} }
