<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\AI;

use Domains\Growth\Application\AI\GrowthAutonomousContentPrompt;
use Domains\Growth\Application\Contract\GrowthAutonomousContentGatewayInterface;
use Domains\Growth\Application\DTO\AutonomousContentDraft;
use InvalidArgumentException;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredLlmGrowthAutonomousContentGateway implements GrowthAutonomousContentGatewayInterface
{
    public function __construct(private StructuredLlmClientInterface $client){}

    public function draft(string $organizationId,string $candidateId,string $recommendationId,string $correlationId,array $context):AutonomousContentDraft
    {
        $response=$this->client->complete(new StructuredLlmRequest(
            systemPrompt:GrowthAutonomousContentPrompt::system(),
            userPrompt:GrowthAutonomousContentPrompt::task(),
            context:$context,
            responseSchema:GrowthAutonomousContentPrompt::schema(),
            maxOutputTokens:1400,
            organizationId:$organizationId,
            useCase:'growth.engagement.content_draft',
            correlationId:$correlationId,
        ));
        $output=$response->output;
        return new AutonomousContentDraft(
            $this->required($output,'body'),
            $this->strings($output['evidence_ids']??null,'evidence_ids',true),
            $this->riskFlags($output['risk_flags']??null),
            $this->confidence($output['confidence']??null),
            $response->provider,$response->model,
            GrowthAutonomousContentPrompt::PROMPT_VERSION,GrowthAutonomousContentPrompt::SCHEMA_VERSION,
            $response->inputTokens,$response->outputTokens,$response->costAmount,$response->costCurrency,
        );
    }

    /** @param array<string,mixed> $output */
    private function required(array $output,string $key):string
    {
        $value=$output[$key]??null;
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth content LLM output is missing '.$key.'.');
        return trim($value);
    }

    /** @return list<string> */
    private function strings(mixed $value,string $field,bool $required=false):array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Growth content LLM '.$field.' must be a list.');
        $out=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException('Growth content LLM '.$field.' contains invalid value.');
            $out[trim($item)]=true;
        }
        $out=array_keys($out);
        if($required&&$out===[])throw new InvalidArgumentException('Growth content LLM '.$field.' must not be empty.');
        return $out;
    }

    /** @return list<string> */
    private function riskFlags(mixed $value):array
    {
        $flags=$this->strings($value,'risk_flags');
        $allowed=array_fill_keys(GrowthAutonomousContentPrompt::RISK_FLAGS,true);
        foreach($flags as $flag)if(!isset($allowed[$flag]))throw new InvalidArgumentException('Growth content LLM returned unsupported risk flag.');
        sort($flags,SORT_STRING);
        return $flags;
    }

    private function confidence(mixed $value):float
    {
        if(!is_int($value)&&!is_float($value))throw new InvalidArgumentException('Growth content LLM confidence must be numeric.');
        $value=(float)$value;
        if($value<0.0||$value>1.0)throw new InvalidArgumentException('Growth content LLM confidence is out of range.');
        return $value;
    }
}
