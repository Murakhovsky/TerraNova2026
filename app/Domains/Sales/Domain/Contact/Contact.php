<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Contact;

use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Contact
{
    public function __construct(
        public ContactId $id,
        public OrganizationId $organizationId,
        public string $name,
        public ?string $email = null,
        public ?string $phone = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Contact name is required.');
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Contact email is invalid.');
        }
        if ($phone !== null && trim($phone) === '') {
            throw new InvalidArgumentException('Contact phone cannot be blank.');
        }
    }
}
