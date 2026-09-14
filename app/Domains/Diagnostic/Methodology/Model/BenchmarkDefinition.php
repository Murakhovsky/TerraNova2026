<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Methodology\Model;
final readonly class BenchmarkDefinition
{
    public function __construct(public string $id,public string $metricId,public string $name,public array $segments,public array $bands,public string $unit,public string $source='',public ?string $validFrom=null,public ?string $validTo=null){}
}
