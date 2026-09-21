<?php

declare(strict_types=1);

namespace App\Web\Experience\Realtime;

use InvalidArgumentException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Twig\Environment;

final readonly class RealtimeStreamPublisher
{
    private const TEMPLATE_PREFIX = 'experience/realtime/streams/';

    public function __construct(
        private HubInterface $hub,
        private Environment $twig,
    ) {
    }

    /** @param array<string,mixed> $context */
    public function publish(
        RealtimeTopic $topic,
        string $template,
        array $context = [],
    ): string {
        if (
            !str_starts_with($template, self::TEMPLATE_PREFIX)
            || !str_ends_with($template, '.stream.html.twig')
            || str_contains($template, '..')
        ) {
            throw new InvalidArgumentException('Realtime templates must use the canonical stream template namespace.');
        }

        $html = $this->twig->render($template, $context);

        if (!str_contains($html, '<turbo-stream')) {
            throw new InvalidArgumentException('Realtime template must render at least one Turbo Stream action.');
        }

        return $this->hub->publish(new Update($topic->value, $html, true));
    }
}
