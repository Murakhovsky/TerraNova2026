<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\GrowthJsonSignalSource;

interface GrowthJsonSignalSourceRepositoryInterface
{
    public function create(GrowthJsonSignalSource $source,int $actorId):void;
    public function lock(string $organizationId,string $sourceId):GrowthJsonSignalSource;
    public function update(GrowthJsonSignalSource $source,int $actorId):void;
    /** @return array<string,mixed>|null */
    public function view(string $organizationId,string $sourceId):?array;
    /** @return list<array<string,mixed>> */
    public function listAll(string $organizationId,int $limit=200):array;
    /** @return list<array<string,mixed>> */
    public function listEnabled(string $organizationId,int $limit=200):array;
}
