<?php
declare(strict_types=1);
namespace Domains\Sales\Application\DTO;
final readonly class ChangeDealStageCommand { public function __construct(public string $organizationId,public string $dealId,public string $targetStageId,public string $actorType,public string $actorId,public string $correlationId){} }
