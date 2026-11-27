<?php
declare(strict_types=1);
namespace Domains\Marketing\Domain;

use DomainException;

/**
 * Exact integer ceiling for an operator-attested, isolated analyst profile.
 *
 * A valid receipt proves only that Hermes reported counts within the configured
 * ceilings. It is not a tokenizer, invoice, or proof that the provider stopped
 * generating at a hard cap. That requires separate operator/live acceptance.
 */
final readonly class MarketingAnalystTokenBudget
{
    private const MAX_TOKENS = 1000000;

    public function __construct(
        public int $maxPromptTokens,
        public int $maxCompletionTokens,
        public int $maxTotalTokens,
        public string $controlEvidenceRef,
    ) {
        if ($maxPromptTokens < 1 || $maxCompletionTokens < 1
            || $maxTotalTokens < max($maxPromptTokens, $maxCompletionTokens)
            || $maxTotalTokens > self::MAX_TOKENS
            || $maxTotalTokens > $maxPromptTokens + $maxCompletionTokens
            || !preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D', $controlEvidenceRef)) {
            throw new DomainException('Invalid analyst token ceilings or operator evidence reference.');
        }
    }

    /** Accept only the deployment-controlled schema; numeric strings and floats are not counts. */
    public static function fromConfiguration(mixed $raw): self
    {
        if ($raw instanceof \stdClass) $raw = get_object_vars($raw);
        if (!is_array($raw)) throw new DomainException('Invalid analyst token budget configuration.');
        $keys = array_keys($raw);
        sort($keys);
        if ($keys !== ['control_evidence_ref', 'max_completion_tokens', 'max_prompt_tokens', 'max_total_tokens']
            || !is_int($raw['max_prompt_tokens']) || !is_int($raw['max_completion_tokens'])
            || !is_int($raw['max_total_tokens']) || !is_string($raw['control_evidence_ref'])) {
            throw new DomainException('Invalid analyst token budget configuration.');
        }

        return new self($raw['max_prompt_tokens'], $raw['max_completion_tokens'],
            $raw['max_total_tokens'], $raw['control_evidence_ref']);
    }

    /** @return array{max_prompt_tokens:int,max_completion_tokens:int,max_total_tokens:int} */
    public function limits(): array
    {
        return ['max_prompt_tokens' => $this->maxPromptTokens,
            'max_completion_tokens' => $this->maxCompletionTokens,
            'max_total_tokens' => $this->maxTotalTokens];
    }

    /** Canonical operator configuration for the existing approved configuration hash. */
    public function configuration(): array
    {
        return ['control_evidence_ref' => $this->controlEvidenceRef, ...$this->limits()];
    }

    /**
     * Strict readback: exact integer counts (not estimates); reject absent,
     * inconsistent, zero/negative, or over-budget results. Additional provider
     * metadata is never carried through. Bounds are checked before addition.
     *
     * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int,limits:array,source:string,hard_cap_verified:bool}
     */
    public function receipt(mixed $usage): array
    {
        if (!is_array($usage)) throw new DomainException('Analyst token usage unavailable.');
        $prompt = $usage['prompt_tokens'] ?? null;
        $completion = $usage['completion_tokens'] ?? null;
        $total = $usage['total_tokens'] ?? null;
        if (!is_int($prompt) || !is_int($completion) || !is_int($total)
            || $prompt < 1 || $completion < 1 || $total < 1
            || $prompt > $this->maxPromptTokens || $completion > $this->maxCompletionTokens
            || $total > $this->maxTotalTokens || $total !== $prompt + $completion) {
            throw new DomainException('Analyst token usage missing, inconsistent or exceeds the approved ceiling.');
        }

        return ['prompt_tokens' => $prompt, 'completion_tokens' => $completion,
            'total_tokens' => $total, 'limits' => $this->limits(),
            'source' => 'HERMES_REPORTED', 'hard_cap_verified' => false];
    }
}
