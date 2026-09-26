<?php

declare(strict_types=1);

namespace App\Web\Property\ViewModel;

final readonly class PublicPropertyDetailViewModel
{
    /**
     * @param list<array{url:string,alt:string}> $images
     * @param list<array{label:string,value:string}> $summaryFacts
     * @param list<array{title:string,items:list<array{label:string,value:string}>}> $characteristicGroups
     * @param list<array{label:string,value:string}> $features
     * @param list<string> $fitHighlights
     * @param list<array<string,mixed>> $groupedProperties
     * @param list<array<string,mixed>> $relatedProperties
     * @param array<string,string> $agent
     * @param array<string,mixed> $breadcrumbSchema
     * @param array<string,mixed> $productSchema
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $publicId,
        public string $title,
        public string $shortDescription,
        public string $description,
        public string $dealLabel,
        public string $dealType,
        public string $typeName,
        public string $locationLabel,
        public string $priceLabel,
        public ?float $priceAmount,
        public string $priceCurrency,
        public string $coverUrl,
        public array $images,
        public array $summaryFacts,
        public array $characteristicGroups,
        public array $features,
        public array $fitHighlights,
        public array $groupedProperties,
        public string $groupTitle,
        public array $relatedProperties,
        public array $agent,
        public string $presentationUrl,
        public string $pdfUrl,
        public string $tourUrl,
        public string $videoUrl,
        public array $breadcrumbSchema,
        public array $productSchema,
        public string $metaTitle,
        public string $metaDescription,
        public string $metaImage,
        public string $canonicalUrl,
        public ?string $notice = null,
        public ?string $error = null,
    ) {
    }

    public function state(): string
    {
        return $this->error === null ? 'normal' : 'error';
    }

    public function imageCount(): int
    {
        return count($this->images);
    }
}
