<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Contract;

/** Worker-only extension of the durable, tenant-owned analyst execution receipt. */
interface MarketingAnalystRemoteRunJournal
{
    public function beginRemoteSubmission(string $org,string $id,string $actor,string $profile,string $hash):void;
    public function attachRemoteRun(string $org,string $id,string $remoteId):void;
    public function observeRemoteRun(string $org,string $id,string $remoteId,string $status):void;
    public function requestRemoteStop(string $org,string $id,string $remoteId):void;
}
