<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Support;

use Domains\Sales\Application\Contract\ClientCaseCommandRepositoryInterface;
use RuntimeException;

final class ClientCasePeople
{
    public static function findOrCreate(
        ClientCaseCommandRepositoryInterface $repository,
        string $organizationId,
        array $person,
    ): int {
        $existing = $repository->findPerson($organizationId, $person['email'] ?? null, $person['phone'] ?? null);
        if ($existing) {
            $personId = (int) $existing['id'];
            if (!$repository->refreshPerson($organizationId, $personId, [
                'full_name' => $person['full_name'],
                'phone' => $person['phone'],
                'email' => $person['email'],
                'telegram' => $person['telegram'],
            ])) {
                throw new RuntimeException('Existing person could not be refreshed.');
            }
            return $personId;
        }
        return $repository->createPerson($organizationId, $person);
    }
}
