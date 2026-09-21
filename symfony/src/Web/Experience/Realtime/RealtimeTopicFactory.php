<?php

declare(strict_types=1);

namespace App\Web\Experience\Realtime;

use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;

final readonly class RealtimeTopicFactory
{
    private const BASE = 'https://realtime.cos.internal';

    public function organization(string $organizationId): RealtimeTopic
    {
        return new RealtimeTopic(sprintf(
            '%s/organizations/%s',
            self::BASE,
            $this->segment($organizationId, 'organization'),
        ));
    }

    public function workspace(string $organizationId, string $workspaceId): RealtimeTopic
    {
        return new RealtimeTopic(sprintf(
            '%s/organizations/%s/workspaces/%s',
            self::BASE,
            $this->segment($organizationId, 'organization'),
            $this->segment($workspaceId, 'workspace'),
        ));
    }

    public function entity(string $organizationId, EntityRef $entity): RealtimeTopic
    {
        return new RealtimeTopic(sprintf(
            '%s/organizations/%s/entities/%s/%s',
            self::BASE,
            $this->segment($organizationId, 'organization'),
            $this->segment($entity->type, 'entity type'),
            $this->segment($entity->id, 'entity id'),
        ));
    }

    public function user(string $organizationId, string $userId): RealtimeTopic
    {
        return new RealtimeTopic(sprintf(
            '%s/organizations/%s/users/%s',
            self::BASE,
            $this->segment($organizationId, 'organization'),
            $this->segment($userId, 'user'),
        ));
    }

    private function segment(string $value, string $label): string
    {
        $value = trim($value);

        if (
            $value === ''
            || mb_strlen($value) > 160
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value)
        ) {
            throw new InvalidArgumentException(sprintf('Realtime %s identifier is invalid.', $label));
        }

        return rawurlencode($value);
    }
}
