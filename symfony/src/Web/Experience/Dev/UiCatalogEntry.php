<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use InvalidArgumentException;

final readonly class UiCatalogEntry
{
    /** @param list<string> $states */
    public function __construct(
        public string $name,
        public string $category,
        public string $description,
        public array $states,
        public string $reference,
        public string $maturity = 'stable',
    ) {
        if (!preg_match('/^Cos[A-Za-z0-9]+$/', $this->name)) {
            throw new InvalidArgumentException('UI Catalog component name must use canonical Cos* naming.');
        }

        if (trim($this->category) === '' || trim($this->description) === '' || trim($this->reference) === '') {
            throw new InvalidArgumentException('UI Catalog entry requires category, description and reference.');
        }

        if (!in_array($this->maturity, ['stable', 'reference', 'experimental'], true)) {
            throw new InvalidArgumentException('Unsupported UI Catalog maturity: ' . $this->maturity);
        }
    }

    /** @return array{name:string,category:string,description:string,states:list<string>,reference:string,maturity:string,search:string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'states' => $this->states,
            'reference' => $this->reference,
            'maturity' => $this->maturity,
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
