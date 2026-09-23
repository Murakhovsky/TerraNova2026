<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\AI;

use Domains\Growth\Application\AI\GrowthExperimentDecisionPrompt;
use Domains\Growth\Application\Contract\GrowthExperimentDecisionGatewayInterface;
use Domains\Growth\Application\DTO\ExperimentDecisionDraft;
use Domains\Growth\Domain\ExperimentDecisionType;
use InvalidArgumentException;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredLlmGrowthExperimentDecisionGateway implements GrowthExperimentDecisionGatewayInterface
{
    public function __construct(private StructuredLlmClientInterface $client){}

    public function recommend(
        string $organizationId,string $experimentId,string $correlationId,array $context
    ):ExperimentDecisionDraft {
        $response=$this->client->complete(new StructuredLlmRequest(
            systemPrompt:GrowthExperimentDecisionPrompt::system(),
            userPrompt:GrowthExperimentDecisionPrompt::task(),
            context:$context,
            responseSchema:GrowthExperimentDecisionPrompt::schema(),
            maxOutputTokens:1200,
            organizationId:$organizationId,
            useCase:'growth.experiment.decision',
            correlationId:$correlationId,
        ));

        $output=$response->output;
        $decisionType=ExperimentDecisionType::tryFrom($this->required($output,'decision_type'))
            ?? throw new InvalidArgumentException('Growth experiment decision LLM returned unknown decision_type.');

        $variant=$output['promoted_variant_key']??null;
        if($variant!==null){
            if(!is_string($variant)||trim($variant)===''){
                throw new InvalidArgumentException('Growth experiment decision LLM promoted_variant_key is invalid.');
            }
            $variant=trim($variant);
        }

        return new ExperimentDecisionDraft(
            $decisionType,$variant,$this->required($output,'rationale'),
            $this->strings($output['evidence_ids']??null,'evidence_ids',true),
            $this->strings($output['risks']??null,'risks'),
            $this->strings($output['assumptions']??null,'assumptions'),
            $this->confidence($output['confidence']??null),
            $response->provider,$response->model,
            GrowthExperimentDecisionPrompt::PROMPT_VERSION,
            GrowthExperimentDecisionPrompt::SCHEMA_VERSION,
            $response->inputTokens,$response->outputTokens,$response->costAmount,$response->costCurrency,
        );
    }

    /** @param array<string,mixed> $output */
    private function required(array $output,string $key):string
    {
        $value=$output[$key]??null;
        if(!is_string($value)||trim($value)===''){
            throw new InvalidArgumentException('Growth experiment decision LLM output is missing '.$key.'.');
        }
        return trim($value);
    }

    /** @return list<string> */
    private function strings(mixed $value,string $field,bool $required=false):array
    {
        if(!is_array($value)||!array_is_list($value)){
            throw new InvalidArgumentException('Growth experiment decision LLM '.$field.' must be a list.');
        }
        $out=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)===''){
                throw new InvalidArgumentException('Growth experiment decision LLM '.$field.' contains invalid value.');
            }
            $out[trim($item)]=true;
        }
        $out=array_keys($out);
        if($required&&$out===[]){
            throw new InvalidArgumentException('Growth experiment decision LLM '.$field.' must not be empty.');
        }
        return $out;
    }

    private function confidence(mixed $value):float
    {
        if(!is_int($value)&&!is_float($value)){
            throw new InvalidArgumentException('Growth experiment decision LLM confidence must be numeric.');
        }
        $value=(float)$value;
        if($value<0.0||$value>1.0){
            throw new InvalidArgumentException('Growth experiment decision LLM confidence is out of range.');
        }
        return $value;
    }
}
