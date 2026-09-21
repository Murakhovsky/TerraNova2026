<?php

declare(strict_types=1);

namespace App\Web\Experience\Native;

use App\Web\Experience\Extension\EntityLinkResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;

final readonly class DeepLinkResolver
{
    public function __construct(
        private EntityLinkResolver $links,
    ) {
    }

    public function resolve(WebExtensionContext $context, EntityRef $entity): ?DeepLink
    {
        $link = $this->links->resolve($context, $entity);
        if ($link === null) {
            return null;
        }

        return new DeepLink(
            entity: $entity,
            webPath: $link->path,
            nativeUri: sprintf(
                'cos://entity/%s/%s',
                rawurlencode($entity->type),
                rawurlencode($entity->id),
            ),
        );
    }
}
