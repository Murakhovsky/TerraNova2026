<?php
declare(strict_types=1);

namespace Domains\Growth\Infrastructure\AI;

use Domains\Growth\Application\AI\GrowthResponseClassificationPrompt;
use Domains\Growth\Application\Contract\GrowthResponseClassificationGatewayInterface;
use Domains\Growth\Application\DTO\GrowthResponseClassificationDraft;
use Domains\Growth\Domain\GrowthResponseIntent;
use Domains\Growth\Domain\GrowthResponseNextOwner;
use Domains\Growth\Domain\GrowthResponseSentiment;
use Domains\Growth\Domain\GrowthResponseUrgency;
use InvalidArgumentException;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredLlmGrowthResponseClassificationGateway implements GrowthResponseClassificationGatewayInterface
{
    public function __construct(private StructuredLlmClientInterface $client){}

    public function classify(string $organizationId,string $correlationId,array $context):GrowthResponseClassificationDraft
    {
        $response=$this->client->complete(new StructuredLlmRequest(
            systemPrompt:GrowthResponseClassificationPrompt::system(),
            userPrompt:GrowthResponseClassificationPrompt::task(),
            context:$context,
            responseSchema:GrowthResponseClassificationPrompt::schema(),
            maxOutputTokens:800,
            organizationId:$organizationId,
            useCase:'growth.engagement.response_classify',
            correlationId:$correlationId,
        ));
        $output=$response->output;

        return new GrowthResponseClassificationDraft(
            GrowthResponseIntent::tryFrom($this->required($output,'intent'))
                ??throw new InvalidArgumentException('Growth response classifier returned unknown intent.'),
            GrowthResponseSentiment::tryFrom($this->required($output,'sentiment'))
                ??throw new InvalidArgumentException('Growth response classifier returned unknown sentiment.'),
            GrowthResponseUrgency::tryFrom($this->required($output,'urgency'))
                ??throw new InvalidArgumentException('Growth response classifier returned unknown urgency.'),
            $this->required($output,'summary'),
            $this->optional($output,'requested_action'),
            GrowthResponseNextOwner::tryFrom($this->required($output,'recommended_next_owner'))
                ??throw new InvalidArgumentException('Growth response classifier returned unknown next owner.'),
            $this->confidence($output['confidence']??null),
            $response->provider,$response->model,
            GrowthResponseClassificationPrompt::PROMPT_VERSION,GrowthResponseClassificationPrompt::SCHEMA_VERSION,
            $response->inputTokens,$response->outputTokens,$response->costAmount,$response->costCurrency,
        );
    }

    private function required(array $output,string $key):string
    {
        $value=$output[$key]??null;
        if(!is_string($value)||trim($value)==='')throw new InvalidArgumentException('Growth response classifier output is missing '.$key.'.');
        return trim($value);
    }

    private function optional(array $output,string $key):?string
    {
        $value=$output[$key]??null;
        if(!is_string($value))throw new InvalidArgumentException('Growth response classifier '.$key.' must be a string.');
        $value=trim($value);
        return $value===''?null:$value;
    }

    private function confidence(mixed $value):float
    {
        if(!is_int($value)&&!is_float($value))throw new InvalidArgumentException('Growth response classifier confidence must be numeric.');
        $value=(float)$value;
        if($value<0.0||$value>1.0)throw new InvalidArgumentException('Growth response classifier confidence is out of range.');
        return $value;
    }
}
