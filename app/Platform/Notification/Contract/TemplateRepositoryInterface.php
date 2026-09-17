<?php
declare(strict_types=1);

namespace Platform\Notification\Contract;

use Platform\Notification\Model\Channel;
use Platform\Notification\Model\Template;

interface TemplateRepositoryInterface
{
    public function find(string $key, Channel $channel, string $locale): ?Template;
}
