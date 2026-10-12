<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Application\Contract;

/**
 * Lifecycle boundary only. Provider-specific implementations must validate
 * subscription ACK, sequence and snapshot before allowing any trusted publish.
 */
interface StreamingWorkerTransportInterface
{
    public function open(string $organizationId,string $sourceId,int $generation):void;
    /** @param list<string> $keys */
    public function subscribe(array $keys,int $generation):void;
    public function pump(int $generation,int $timeoutMilliseconds):void;
    public function close():void;
}
