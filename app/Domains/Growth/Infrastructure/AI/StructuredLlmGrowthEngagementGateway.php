<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\AI;

use Domains\Growth\Application\AI\GrowthEngagementPrompt;
use Domains\Growth\Application\Contract\GrowthEngagementGatewayInterface;
use Domains\Growth\Application\DTO\EngagementRecommendationDraft;
use Domains\Growth\Domain\EngagementChannel;
use Domains\Growth\Domain\NextBestActionType;
use InvalidArgumentException;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredLlmGrowthEngagementGateway implements GrowthEngagementGatewayInterface
{
    public function __construct(private StructuredLlmClientInterface $client){}

    public function recommend(string $organizationId,string $candidateId,string $correlationId,array $context):EngagementRecommendationDraft
    {
        $response=$this->client->complete(new StructuredLlmRequest(
            systemPrompt:GrowthEngagementPrompt::system(),
            userPrompt:GrowthEngagementPrompt::task(),
            context:$context,
            responseSchema:GrowthEngagementPrompt::schema(),
            maxOutputTokens:1200,
            organizationId:$organizationId,
            useCase:'growth.engagement.recommend',
            correlationId:$correlationId,
        ));
        $output=$response->output;
        $actionType=NextBestActionType::tryFrom($this->required($output,'action_type'))
            ?? throw new InvalidArgumentException('Growth engagement LLM returned unknown action_type.');
        $channel=EngagementChannel::tryFrom($this->required($output,'channel'))
            ?? throw new InvalidArgumentException('Growth engagement LLM returned unknown channel.');
        if(!$actionType->allowsChannel($channel)){
            throw new InvalidArgumentException('Growth engagement LLM returned incompatible action_type/channel.');
        }
        $contactId=$output['contact_id']??null;
        if($contactId!==null&&(!is_string($contactId)||trim($contactId)==='')){
            throw new InvalidArgumentException('Growth engagement LLM contact_id is invalid.');
        }

        return new EngagementRecommendationDraft(
            $actionType,$channel,$contactId===null?null:trim($contactId),
            $this->required($output,'rationale'),$this->required($output,'message_angle'),
            $this->strings($output['evidence_ids']??null,'evidence_ids',true),
            $this->strings($output['unknowns']??null,'unknowns'),
            $this->confidence($output['confidence']??null),
            $response->provider,$response->model,
            GrowthEngagementPrompt::PROMPT_VERSION,GrowthEngagementPrompt::SCHEMA_VERSION,
            $response->inputTokens,$response->outputTokens,$response->costAmount,$response->costCurrency,
        );
    }

    /** @param array<string,mixed> $output */
    private function required(array $output,string $key):string
    {
        $value=$output[$key]??null;
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth engagement LLM output is missing '.$key.'.');
        return trim($value);
    }

    /** @return list<string> */
    private function strings(mixed $value,string $field,bool $required=false):array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Growth engagement LLM '.$field.' must be a list.');
        $out=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException('Growth engagement LLM '.$field.' contains invalid value.');
            $out[trim($item)]=true;
        }
        $out=array_keys($out);
        if($required&&$out===[])throw new InvalidArgumentException('Growth engagement LLM '.$field.' must not be empty.');
        return $out;
    }

    private function confidence(mixed $value):float
    {
        if(!is_int($value)&&!is_float($value))throw new InvalidArgumentException('Growth engagement LLM confidence must be numeric.');
        $value=(float)$value;
        if($value<0.0||$value>1.0)throw new InvalidArgumentException('Growth engagement LLM confidence is out of range.');
        return $value;
    }
}
