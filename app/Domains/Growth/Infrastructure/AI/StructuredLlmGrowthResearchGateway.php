<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\AI;

use Domains\Growth\Application\AI\GrowthResearchPrompt;
use Domains\Growth\Application\Contract\GrowthResearchGatewayInterface;
use Domains\Growth\Application\DTO\ResearchProposalDraft;
use InvalidArgumentException;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredLlmGrowthResearchGateway implements GrowthResearchGatewayInterface
{
    public function __construct(private StructuredLlmClientInterface $client) {}

    public function propose(
        string $organizationId,
        string $candidateId,
        string $correlationId,
        array $context,
    ): ResearchProposalDraft {
        $response=$this->client->complete(new StructuredLlmRequest(
            systemPrompt:GrowthResearchPrompt::system(),
            userPrompt:GrowthResearchPrompt::task(),
            context:$context,
            responseSchema:GrowthResearchPrompt::schema(),
            maxOutputTokens:1800,
            organizationId:$organizationId,
            useCase:'growth.research.propose',
            correlationId:$correlationId,
        ));

        $output=$response->output;
        return new ResearchProposalDraft(
            $this->required($output,'why_it_matters'),
            $this->required($output,'problem_hypothesis'),
            $this->required($output,'why_now'),
            $this->strings($output['evidence_ids']??null,'evidence_ids',true),
            $this->strings($output['counter_evidence_ids']??null,'counter_evidence_ids'),
            $this->strings($output['assumptions']??null,'assumptions'),
            $this->strings($output['unknowns']??null,'unknowns'),
            $this->confidence($output['confidence']??null),
            $response->provider,$response->model,
            GrowthResearchPrompt::PROMPT_VERSION,GrowthResearchPrompt::SCHEMA_VERSION,
            $response->inputTokens,$response->outputTokens,$response->costAmount,$response->costCurrency,
        );
    }

    /** @param array<string,mixed> $output */
    private function required(array $output,string $key): string
    {
        $value=$output[$key]??null;
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth research LLM output is missing '.$key.'.');
        return trim($value);
    }

    /** @return list<string> */
    private function strings(mixed $value,string $field,bool $required=false): array
    {
        if(!is_array($value)||!array_is_list($value))throw new InvalidArgumentException('Growth research LLM '.$field.' must be a list.');
        $out=[];
        foreach($value as $item){
            if(!is_string($item)||trim($item)==='')throw new InvalidArgumentException('Growth research LLM '.$field.' contains invalid value.');
            $out[trim($item)]=true;
        }
        $out=array_keys($out);
        if($required&&$out===[])throw new InvalidArgumentException('Growth research LLM '.$field.' must not be empty.');
        return $out;
    }

    private function confidence(mixed $value): float
    {
        if(!is_float($value)&&!is_int($value))throw new InvalidArgumentException('Growth research LLM confidence must be numeric.');
        $value=(float)$value;
        if($value<0.0||$value>1.0)throw new InvalidArgumentException('Growth research LLM confidence is out of range.');
        return $value;
    }
}
