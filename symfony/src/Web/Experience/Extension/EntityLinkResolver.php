<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension;

use App\Web\Experience\Extension\Model\EntityLink;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use LogicException;

final readonly class EntityLinkResolver
{
    public function __construct(
        private WebExtensionProviderRegistry $providers,
    ) {
    }

    public function resolve(WebExtensionContext $context, EntityRef $entity): ?EntityLink
    {
        $resolved = [];

        foreach ($this->providers->forContext($context)->entityLinks() as $provider) {
            $link = $provider->link($context, $entity);
            if ($link === null) {
                continue;
            }

            if (!str_starts_with($link->path, '/')) {
                throw new LogicException('Canonical entity links must be root-relative Web paths.');
            }

            $resolved[] = $link;
        }

        if (count($resolved) > 1) {
            throw new LogicException(sprintf('Multiple canonical links resolved for entity %s.', $entity->key()));
        }

        return $resolved[0] ?? null;
    }
}
