<?php
declare(strict_types=1);

namespace Platform\Notification\Contract;

use Platform\Notification\Model\RenderedNotification;
use Platform\Notification\Model\Template;

interface TemplateRendererInterface
{
    /** @param array<string,mixed> $variables */
    public function render(Template $template, array $variables): RenderedNotification;
}
