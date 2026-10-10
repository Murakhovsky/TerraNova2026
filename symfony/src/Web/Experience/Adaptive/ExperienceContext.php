<?php
declare(strict_types=1);

namespace App\Web\Experience\Adaptive;

use InvalidArgumentException;

/**
 * Trusted, server-resolved context. UI mode is NOT an authorization or tenant source.
 */
final readonly class ExperienceContext
{
    /**
     * @param list<array{id:string,level:int,capability?:string,critical?:bool}> $sections
     * @param list<string> $availableCapabilities
     * @param list<string> $expandedSections
     * @param list<array<string,mixed>> $decisionRequests
     * @param list<array<string,mixed>> $blockingRisks
     * @param array<string,string> $actionCapabilities
     */
    public function __construct(
        public string $userId,
        public string $organizationId,
        public ExperienceMode $mode,
        public array $sections = [],
        public array $availableCapabilities = [],
        public array $expandedSections = [],
        public array $decisionRequests = [],
        public array $blockingRisks = [],
        public array $actionCapabilities = [],
    ) {
        if (trim($this->userId) === '' || trim($this->organizationId) === '') {
            throw new InvalidArgumentException('Adaptive experience requires trusted user and tenant identities.');
        }
        $ids = [];
        foreach ($this->sections as $section) {
            if (!is_array($section)
                || !is_string($section['id'] ?? null)
                || !preg_match('/^[a-z][a-z0-9_.-]*$/', $section['id'])
                || !is_int($section['level'] ?? null)
                || $section['level'] < 0 || $section['level'] > 3) {
                throw new InvalidArgumentException('Invalid declarative experience section.');
            }
            if (array_diff(array_keys($section), ['id', 'level', 'capability', 'critical']) !== []) {
                throw new InvalidArgumentException('Experience section contains unsupported executable/content keys.');
            }
            if (isset($section['capability']) && (!is_string($section['capability']) || $section['capability'] === '')) {
                throw new InvalidArgumentException('Invalid section capability identity.');
            }
            if (isset($section['critical']) && !is_bool($section['critical'])) {
                throw new InvalidArgumentException('Invalid section critical status.');
            }
            if (isset($ids[$section['id']])) {
                throw new InvalidArgumentException('Duplicate experience section.');
            }
            $ids[$section['id']] = true;
        }
        foreach ([$this->availableCapabilities, $this->expandedSections] as $values) {
            if (!array_is_list($values)) {
                throw new InvalidArgumentException('Experience capabilities/expanded sections must be lists.');
            }
            foreach ($values as $value) {
                if (!is_string($value) || $value === '') {
                    throw new InvalidArgumentException('Invalid experience capability or section identity.');
                }
            }
        }
        foreach ($this->actionCapabilities as $action => $capability) {
            if (!is_string($action) || !is_string($capability) || $action === '' || $capability === '') {
                throw new InvalidArgumentException('Invalid UIAction capability binding.');
            }
        }
    }
}
