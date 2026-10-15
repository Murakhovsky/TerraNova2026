<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\MarketData;

use DomainException;

/**
 * Per-source/instrument/channel fail-closed stream recovery gate.
 *
 * It does not infer provider sequence rules. A provider-specific adapter must
 * validate snapshots, delta bridging and checksums before calling its methods.
 * This component cannot, by itself, authorize a MarketState publication.
 */
final class StreamingRecoveryGate
{
    private int $generation=0;
    private string $state='DISABLED';
    private ?string $lastSequence=null;
    private ?string $reason=null;
    private bool $snapshotVerified=false;

    public function generation():int { return $this->generation; }
    public function state():string { return $this->state; }
    public function reason():?string { return $this->reason; }
    public function lastSequence():?string { return $this->lastSequence; }
    public function trusted():bool { return $this->state==='TRUSTED'; }

    public function start():int
    {
        $this->generation++;
        $this->state='CONNECTING';
        $this->lastSequence=null;
        $this->snapshotVerified=false;
        $this->reason=null;
        return $this->generation;
    }

    public function connected(int $generation):void
    {
        $this->assertGeneration($generation);
        $this->requireState(['CONNECTING']);
        $this->state='SUBSCRIBING';
    }

    public function acknowledged(int $generation):void
    {
        $this->assertGeneration($generation);
        $this->requireState(['SUBSCRIBING']);
        $this->state='SYNCING';
    }

    /**
     * $verified is evidence from a provider-specific snapshot and continuity
     * validator, never the mere presence of a network frame.
     */
    public function snapshot(int $generation,?string $sequence,bool $verified):void
    {
        $this->assertGeneration($generation);
        $this->requireState(['SYNCING']);
        if(!$verified){
            $this->invalidate('SNAPSHOT_VALIDATION_FAILED');
            return;
        }
        $this->lastSequence=$sequence;
        $this->snapshotVerified=true;
    }

    /**
     * For L2 books, $continuityVerified requires adapter-specific validation.
     * The caller must never substitute "sequence increased" for continuity.
     * $freshAndValid includes freshness, normalization and checksum evidence.
     */
    public function recovered(int $generation,bool $continuityVerified,bool $freshAndValid):void
    {
        $this->assertGeneration($generation);
        $this->requireState(['SYNCING']);
        if(!$this->snapshotVerified||!$continuityVerified||!$freshAndValid){
            $this->invalidate('RECOVERY_VALIDATION_FAILED');
            return;
        }
        $this->state='TRUSTED';
        $this->reason=null;
    }

    public function delta(int $generation,?string $sequence,bool $continuityVerified,bool $freshAndValid):void
    {
        $this->assertGeneration($generation);
        $this->requireState(['TRUSTED']);
        if(!$continuityVerified||!$freshAndValid){
            $this->invalidate('DELTA_INTEGRITY_FAILED');
            return;
        }
        $this->lastSequence=$sequence;
    }

    public function invalidate(string $reason):void
    {
        if($reason==='')throw new DomainException('Integrity failure requires reason.');
        $this->state='UNTRUSTED';
        $this->reason=$reason;
        $this->lastSequence=null;
        $this->snapshotVerified=false;
    }

    public function disconnect(int $generation,string $reason='DISCONNECTED'):void
    {
        $this->assertGeneration($generation);
        $this->invalidate($reason);
    }

    public function disable():void
    {
        $this->generation++;
        $this->state='DISABLED';
        $this->snapshotVerified=false;
        $this->lastSequence=null;
        $this->reason='DISABLED';
    }

    private function assertGeneration(int $generation):void
    {
        if($generation!==$this->generation||$generation<1){
            throw new DomainException('Stale streaming connection generation.');
        }
    }

    /** @param list<string> $allowed */
    private function requireState(array $allowed):void
    {
        if(!in_array($this->state,$allowed,true)){
            throw new DomainException('Invalid streaming recovery state transition from '.$this->state.'.');
        }
    }
}
