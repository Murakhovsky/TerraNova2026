<?php
declare(strict_types=1);
namespace Domains\Diagnostic\AI;
use InvalidArgumentException;

final readonly class AiRequest
{
    public function __construct(public string $organizationId, public string $diagnosticId, public array $context, public array $outputSchema)
    { if($organizationId===''||$diagnosticId===''||$outputSchema===[]) throw new InvalidArgumentException('AI request requires tenant, diagnostic and output schema.'); }
}
