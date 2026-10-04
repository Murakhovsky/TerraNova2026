<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use InvalidArgumentException;

final readonly class UiCatalogEntry
{
    /**
     * @param list<string> $states
     * @param list<string> $accessibility
     */
    public function __construct(
        public string $name,
        public string $category,
        public string $description,
        public array $states,
        public string $reference,
        public string $maturity = 'stable',
        public string $owner = 'COS Experience Platform',
        public string $contractVersion = '1.0',
        public array $accessibility = ['keyboard', 'screen_reader', 'theme_parity', 'density_parity'],
    ) {
        if (!preg_match('/^Cos[A-Za-z0-9]+$/', $this->name)) {
            throw new InvalidArgumentException('UI Catalog component name must use canonical Cos* naming.');
        }

        if (trim($this->category) === '' || trim($this->description) === '' || trim($this->reference) === '') {
            throw new InvalidArgumentException('UI Catalog entry requires category, description and reference.');
        }

        if (!in_array($this->maturity, ['stable', 'experimental', 'deprecated'], true)) {
            throw new InvalidArgumentException('Unsupported UI Catalog maturity: ' . $this->maturity);
        }

        if ($this->states === []) {
            throw new InvalidArgumentException('UI Catalog entry requires at least one governed state.');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'states' => $this->states,
            'reference' => $this->reference,
            'maturity' => $this->maturity,
            'owner' => $this->owner,
            'contractVersion' => $this->contractVersion,
            'accessibility' => $this->accessibility,
            'frozen' => $this->maturity === 'stable',
            'search' => strtolower(implode(' ', [
                $this->name,
                $this->category,
                $this->description,
                implode(' ', $this->states),
                $this->maturity,
            ])),
        ];
    }
}
