<?php
declare(strict_types=1);
namespace Domains\Diagnostic\AI;

interface AiGatewayInterface { public function execute(AiOperationDefinition $operation, AiRequest $request): AiResponse; }
