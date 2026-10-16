<?php
declare(strict_types=1);

namespace Platform\Orchestration\Goal;

use DomainException;

/**
 * Preflight counterpart of native DocumentsRuntimeService::render().
 * Guards approved snapshot variables. Does not render or persist documents.
 */
final readonly class FederationProposalTemplateGuard
{
    /** @param list<array<string,mixed>> $plans */
    public function assertResolvable(string $template, array $plans): void
    {
        $matched = preg_match_all('/\\{\\{([^{}]+)\\}\\}/', $template, $matches);
        if ($matched === false
            || substr_count($template, '{{') !== $matched
            || substr_count($template, '}}') !== $matched) {
            throw new DomainException('Malformed proposal template placeholders.');
        }
        $keys = [];
        foreach ($matches[1] as $key) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                throw new DomainException('Unsupported proposal template placeholder syntax.');
            }
            $keys[$key] = true;
        }
        foreach ($plans as $plan) {
            $variables = $plan['steps'][3]['input']['parameters']['variables'] ?? null;
            if (!is_array($variables)) {
                throw new DomainException('Proposal plan has no immutable template variable snapshot.');
            }
            foreach (array_keys($keys) as $key) {
                if (!is_string($variables[$key] ?? null) || trim($variables[$key]) === '') {
                    throw new DomainException('Template needs missing tenant-sourced variable: ' . $key);
                }
            }
        }
    }
}
