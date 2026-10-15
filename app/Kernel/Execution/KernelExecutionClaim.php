<?php
declare(strict_types=1);
namespace Kernel\Execution;

use InvalidArgumentException;

/** The claim token never appears in a public projection. */
final readonly class KernelExecutionClaim
{
    public function __construct(
        public string $organizationId,
        public string $runId,
        public string $kind,
        public string $token,
        public string $correlationId
    ) {
        if(!in_array($kind,['agent','tool'],true) || $organizationId==='' || $runId===''
            || !preg_match('/^[a-f0-9]{32}$/D',$token) || $correlationId==='') {
            throw new InvalidArgumentException('Canonical Kernel claim identity is invalid.');
        }
    }
}
