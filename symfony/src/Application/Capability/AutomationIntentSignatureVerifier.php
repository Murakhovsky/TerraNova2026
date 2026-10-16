<?php
declare(strict_types=1);

namespace App\Application\Capability;

use DomainException;

/** Transport authentication only. A valid signature grants proposal, never execution. */
final readonly class AutomationIntentSignatureVerifier
{
    public function __construct(
        private string $aiSecret,
        private string $cronSecret,
        private string $organizationId,
    ) {}

    public function organization():string
    {
        if ($this->organizationId==='') throw new DomainException('Automation tenant rollout is disabled.');
        return $this->organizationId;
    }

    public function verify(string $origin,string $timestamp,string $nonce,string $signature,string $body,?int $now=null):void
    {
        $secret=match($origin) {
            'ai'=>$this->aiSecret, 'cron'=>$this->cronSecret,
            default=>throw new DomainException('Unsupported automation identity.'),
        };
        if ($this->organizationId===''||strlen($secret)<32
            || !preg_match('/^[0-9]{10}$/D',$timestamp)
            || abs(($now??time())-(int)$timestamp)>300
            || !preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$nonce)
            || !preg_match('/^[a-f0-9]{64}$/D',$signature)
            || strlen($body)>65536 || $body==='') {
            throw new DomainException('Machine signature, timestamp, nonce or rollout is invalid.');
        }
        $message=implode("\n",[$origin,$timestamp,$nonce,$body]);
        $expected=hash_hmac('sha256',$message,$secret);
        if (!hash_equals($expected,$signature)) throw new DomainException('Invalid automation signature.');
    }
}
