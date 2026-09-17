<?php
declare(strict_types=1);

namespace Platform\Notification\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Notification
{
    /** @param array<string,mixed> $variables @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public Channel $channel,
        public string $templateKey,
        public Recipient $recipient,
        public array $variables,
        public string $locale,
        public string $correlationId,
        public DateTimeImmutable $createdAt,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '' || trim($this->templateKey) === '' || trim($this->locale) === '' || trim($this->correlationId) === '') {
            throw new InvalidArgumentException('Notification requires id, template, locale and correlation id.');
        }
    }
}
