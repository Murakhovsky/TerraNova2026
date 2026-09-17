<?php
declare(strict_types=1);

namespace Platform\Notification\Model;

use InvalidArgumentException;

final readonly class Template
{
    public function __construct(
        public string $id,
        public string $key,
        public Channel $channel,
        public string $locale,
        public ?string $subject,
        public string $body,
    ) {
        if (trim($this->id) === '' || trim($this->key) === '' || trim($this->locale) === '' || trim($this->body) === '') {
            throw new InvalidArgumentException('Notification template requires id, key, locale and body.');
        }
    }
}
