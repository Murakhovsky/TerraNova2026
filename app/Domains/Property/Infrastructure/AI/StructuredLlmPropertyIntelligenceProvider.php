<?php
declare(strict_types=1);

namespace Domains\Property\Infrastructure\AI;

use Domains\Property\Application\Contract\PropertyIntelligenceProviderInterface;
use Domains\Property\Application\DTO\PropertyIntelligenceContext;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;

final readonly class StructuredLlmPropertyIntelligenceProvider implements PropertyIntelligenceProviderInterface
{
    public function __construct(private StructuredLlmClientInterface $client) {}

    public function infer(PropertyIntelligenceContext $context): array
    {
        $response = $this->client->complete(new StructuredLlmRequest(
            systemPrompt: 'You are a real-estate intelligence engine. Produce derived intelligence only from supplied canonical facts, comparables and market signals. Never rewrite, repair or invent source facts. Estimates are inferences, not truth. Return null for unsupported numeric estimates. Prefer ranges over false precision and lower confidence when evidence is weak or demand coverage is low.',
            userPrompt: 'Estimate market value range, liquidity, demand, market position, price anomaly, inventory risk, expected days on market and a recommended asking price. Explain the main evidence in concise terms.',
            context: $context->evidence(),
            responseSchema: self::schema(),
            maxOutputTokens: 1000,
            organizationId: $context->organizationId,
            useCase: 'property.intelligence.generate',
            correlationId: $context->correlationId ?? $context->assetId,
        ));

        return $response->output + [
            '_meta' => [
                'provider' => $response->provider,
                'model' => $response->model,
                'method' => 'structured_llm',
                'input_tokens' => $response->inputTokens,
                'output_tokens' => $response->outputTokens,
                'cost_amount' => $response->costAmount,
                'cost_currency' => $response->costCurrency,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function schema(): array
    {
        $nullableNumber = ['type' => ['number','null']];
        $nullableInteger = ['type' => ['integer','null'], 'minimum' => 0];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'estimated_market_value_low','estimated_market_value_high','value_currency','liquidity_score','demand_score',
                'market_position','price_anomaly_percent','inventory_risk','expected_dom_min','expected_dom_max',
                'recommended_asking_price','confidence','explanation',
            ],
            'properties' => [
                'estimated_market_value_low' => $nullableNumber,
                'estimated_market_value_high' => $nullableNumber,
                'value_currency' => ['type' => ['string','null']],
                'liquidity_score' => ['type' => ['number','null'], 'minimum' => 0, 'maximum' => 100],
                'demand_score' => ['type' => ['number','null'], 'minimum' => 0, 'maximum' => 100],
                'market_position' => ['type' => 'string', 'enum' => ['UNDERPRICED','FAIR','OVERPRICED','UNKNOWN']],
                'price_anomaly_percent' => $nullableNumber,
                'inventory_risk' => ['type' => 'string', 'enum' => ['LOW','MEDIUM','HIGH','UNKNOWN']],
                'expected_dom_min' => $nullableInteger,
                'expected_dom_max' => $nullableInteger,
                'recommended_asking_price' => $nullableNumber,
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'explanation' => ['type' => ['string','null']],
            ],
        ];
    }
}
