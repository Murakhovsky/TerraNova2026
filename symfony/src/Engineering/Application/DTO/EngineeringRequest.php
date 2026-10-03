<?php
declare(strict_types=1);

namespace App\Engineering\Application\DTO;

use InvalidArgumentException;

final readonly class EngineeringRequest
{
    public function __construct(
        public string $requestId,
        public string $description,
        public ?string $title = null,
        public string $sourceType = 'user',
        public ?string $sourceReference = null,
        public string $priority = 'P2',
        public array $metadata = [],
        public array $constraints = [],
        public array $attachments = [],
        public array $previousContext = [],
    ) {
        if (trim($requestId) === '') throw new InvalidArgumentException('Engineering request id cannot be empty.');
        if (trim($description) === '') throw new InvalidArgumentException('Engineering request description cannot be empty.');
    }

    public function searchText(): string
    {
        return trim(($this->title ?? '').' '.$this->description);
    }
}
