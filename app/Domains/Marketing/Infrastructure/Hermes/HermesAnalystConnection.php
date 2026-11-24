<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Hermes;

use DomainException;
use Platform\Integration\Model\Credential;

/** Operator-provisioned dedicated profile; never constructed from request input. */
final readonly class HermesAnalystConnection
{
    public function __construct(public string $profileBaseUrl,public Credential $credential,
        public bool $isolatedProfileAccepted=false)
    {
        $parts=parse_url($profileBaseUrl);
        if(!is_array($parts)||($parts['scheme']??null)!=='https'
            ||!is_string($parts['host']??null)||!preg_match('/^[a-zA-Z0-9.-]+$/D',$parts['host'])
            ||!preg_match('~^/p/[a-zA-Z0-9_-]{1,64}$~D',(string)($parts['path']??''))
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])
            ||isset($parts['query'])||isset($parts['fragment'])
            ||!str_contains($parts['host'],'.')||filter_var($parts['host'],FILTER_VALIDATE_IP)!==false
            ||$credential->kind!=='hermes_analyst'||!in_array('marketing.advice',$credential->scopes,true)){
            throw new DomainException('Dedicated authenticated HTTPS Hermes analyst profile required.');
        }
    }
}
