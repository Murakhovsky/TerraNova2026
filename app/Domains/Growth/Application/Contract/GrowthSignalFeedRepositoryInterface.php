<?php
declare(strict_types=1);

namespace Domains\Growth\Application\Contract;

use Domains\Growth\Domain\GrowthSignalFeed;

interface GrowthSignalFeedRepositoryInterface
{
    public function create(GrowthSignalFeed $feed,int $actorId):void;
    public function lock(string $organizationId,string $feedId):GrowthSignalFeed;
    public function update(GrowthSignalFeed $feed,int $actorId):void;

    /** @return array<string,mixed>|null */
    public function view(string $organizationId,string $feedId):?array;

    /** @return list<array<string,mixed>> */
    public function listAll(string $organizationId,int $limit=200):array;

    /** @return list<array<string,mixed>> */
    public function listEnabled(string $organizationId,int $limit=200):array;
}
