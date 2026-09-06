<?php
declare(strict_types=1);
namespace Domains\Sales\Application\DTO;
final readonly class ChangeDealStageResult { private function __construct(public bool $successful,public bool $changed,public ?string $previousStageId,public ?string $stageId,public ?string $reason,public bool $requiresApproval=false){} public static function success(string $from,string $to,bool $changed,bool $approval=false):self{return new self(true,$changed,$from,$to,null,$approval);}public static function failure(string $reason):self{return new self(false,false,null,null,$reason);} }
