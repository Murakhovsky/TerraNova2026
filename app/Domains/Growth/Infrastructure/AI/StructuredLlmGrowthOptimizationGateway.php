<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\AI;

use Domains\Growth\Application\AI\GrowthOptimizationPrompt;
use Domains\Growth\Application\Contract\GrowthOptimizationGatewayInterface;
use Domains\Growth\Application\DTO\LearningOptimizationDraft;
use Domains\Growth\Domain\OptimizationTargetType;
use InvalidArgumentException;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredLlmGrowthOptimizationGateway implements GrowthOptimizationGatewayInterface
{
    public function __construct(private StructuredLlmClientInterface $client){}

    public function recommend(string $organizationId,string $correlationId,array $context):LearningOptimizationDraft
    {
        $response=$this->client->complete(new StructuredLlmRequest(
            systemPrompt:GrowthOptimizationPrompt::system(),
            userPrompt:GrowthOptimizationPrompt::task(),
            context:$context,
            responseSchema:GrowthOptimizationPrompt::schema(),
            maxOutputTokens:1800,
            organizationId:$organizationId,
            useCase:'growth.learning.optimize',
            correlationId:$correlationId,
        ));
        $output=$response->output;
        $targetType=OptimizationTargetType::tryFrom($this->required($output,'target_type'))
            ?? throw new InvalidArgumentException('Growth optimization LLM returned unknown target_type.');
        $criteria=$output['proposed_criteria']??null;
        if(!is_array($criteria)||array_is_list($criteria)){
            throw new InvalidArgumentException('Growth optimization LLM proposed_criteria must be an object.');
        }
        $revision=$output['base_revision']??null;
        if(!is_int($revision)||$revision<1){
            throw new InvalidArgumentException('Growth optimization LLM base_revision must be a positive integer.');
        }

        return new LearningOptimizationDraft(
            $targetType,
            $this->required($output,'target_id'),
            $revision,
            $this->required($output,'proposed_name'),
            $criteria,
            $this->required($output,'rationale'),
            $this->strings($output['evidence_ids']??null,'evidence_ids',true),
            $this->strings($output['risks']??null,'risks'),
            $this->strings($output['assumptions']??null,'assumptions'),
            $this->confidence($output['confidence']??null),
            $response->provider,$response->model,
            GrowthOptimizationPrompt::PROMPT_VERSION,GrowthOptimizationPrompt::SCHEMA_VERSION,
            $response->inputTokens,$response->outputTokens,$response->costAmount,$response->costCurrency,
        );
    }

    /** @param array<string,mixed> $output */
    private function required(array $output,string $key):string
    {
        $value=$output[$key]??null;
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth optimization LLM output is missing '.$key.'.');
        return trim($value);
    }

    /** @return list<string> */
    private function strings(mixed $value,string $field,bool $required=false):array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Growth optimization LLM '.$field.' must be a list.');
        $out=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException('Growth optimization LLM '.$field.' contains invalid value.');
            $out[trim($item)]=true;
        }
        $out=array_keys($out);
        if($required&&$out===[])throw new InvalidArgumentException('Growth optimization LLM '.$field.' must not be empty.');
        return $out;
    }

    private function confidence(mixed $value):float
    {
        if(!is_int($value)&&!is_float($value))throw new InvalidArgumentException('Growth optimization LLM confidence must be numeric.');
        $value=(float)$value;
        if($value<0.0||$value>1.0)throw new InvalidArgumentException('Growth optimization LLM confidence is out of range.');
        return $value;
    }
}
