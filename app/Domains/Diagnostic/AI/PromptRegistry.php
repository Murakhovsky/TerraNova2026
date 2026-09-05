<?php
declare(strict_types=1);
namespace Domains\Diagnostic\AI;
use InvalidArgumentException;

final class PromptRegistry
{
    private array $prompts=[];
    public function __construct()
    {
        foreach(['sales_interview:v1','fact_extractor:v1','contradiction_detector:v1','hypothesis_generator:v1','root_cause:v1','recommendation:v1','report_summary:v1'] as $id) $this->prompts[$id]=['id'=>$id,'blocks'=>['system','methodology','criterion_context','company_context','diagnostic_state','conversation_context','task','output_schema']];
    }
    public function get(string $id): array { return $this->prompts[$id]??throw new InvalidArgumentException('Unknown prompt version: '.$id); }
    public function versions(): array { return array_keys($this->prompts); }
}
