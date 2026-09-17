<?php
declare(strict_types=1);

namespace Platform\Notification\Model;

final readonly class RenderedNotification
{
    public function __construct(public ?string $subject, public string $body)
    {
    }
}
