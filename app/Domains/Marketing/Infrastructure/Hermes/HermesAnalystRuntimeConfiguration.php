<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Hermes;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Marketing\Domain\MarketingAnalystAdmissionPolicy;
use Domains\Marketing\Domain\MarketingAnalystTokenBudget;
use Kernel\Shared\Domain\OrganizationId;
use Platform\Integration\Model\Credential;

/** Deployment-controlled, tenant-specific configuration. No client may provide a URL or token. */
final readonly class HermesAnalystRuntimeConfiguration
{
    public HermesAnalystConnection $connection;
    public MarketingAnalystAdmissionPolicy $admission;
    public DateTimeImmutable $acceptedUntil;
    public string $configurationHash;
    public float $maxCostUsd;
    public ?MarketingAnalystTokenBudget $tokenBudget;

    /** @param array<string,mixed> $raw */
    public function __construct(string $org,array $raw)
    {
        $keys=array_keys($raw);sort($keys);
        $required=['accepted_until','cost_control_ref','credential_ref','daily_run_limit','enabled',
            'isolation_evidence_ref','max_cost_usd','profile_ref','profile_url'];
        // Preserve independently approved legacy profiles; a newly declared token
        // contract is strictly validated and included in the immutable hash.
        if($keys!==$required&&$keys!==[...$required,'token_budget']){
            throw new DomainException('Invalid Hermes operator configuration keys.');
        }
        foreach(['profile_ref','profile_url','credential_ref','cost_control_ref','isolation_evidence_ref','accepted_until','max_cost_usd'] as $k){
            if(!is_string($raw[$k]))throw new DomainException('Invalid Hermes configuration field.');
        }
        if(strlen($org)>40||!is_bool($raw['enabled'])||!is_int($raw['daily_run_limit'])
            ||!preg_match('/^env:\\/\\/[A-Z][A-Z0-9_]{2,127}$/D',$raw['credential_ref'])
            ||!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$raw['isolation_evidence_ref'])
            ||!preg_match('/^[A-Za-z0-9_.:-]{8,190}$/D',$raw['cost_control_ref'])
            ||!preg_match('/^(?:0|[1-9][0-9]{0,2})\\.[0-9]{4}$/D',$raw['max_cost_usd'])
            ||(float)$raw['max_cost_usd']<=0.0||(float)$raw['max_cost_usd']>10.0){
            throw new DomainException('Invalid Hermes operator bounds or evidence references.');
        }
        $expiry=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$raw['accepted_until'],new DateTimeZone('UTC'));
        if(!$expiry||$expiry->format('Y-m-d\TH:i:s\Z')!==$raw['accepted_until']){
            throw new DomainException('Invalid Hermes acceptance expiry.');
        }
        $this->acceptedUntil=$expiry;
        $this->maxCostUsd=(float)$raw['max_cost_usd'];
        $this->tokenBudget=array_key_exists('token_budget',$raw)
            ? MarketingAnalystTokenBudget::fromConfiguration($raw['token_budget']) : null;
        if($this->tokenBudget!==null)$raw['token_budget']=$this->tokenBudget->configuration();
        $this->admission=new MarketingAnalystAdmissionPolicy($org,$raw['profile_ref'],$raw['daily_run_limit'],
            $raw['cost_control_ref'],$raw['enabled']);
        $credential=new Credential('mkt-hermes-'.substr(hash('sha256',$org.':'.$raw['profile_ref']),0,24),
            OrganizationId::fromString($org),$raw['profile_ref'],'hermes_analyst',$raw['credential_ref'],['marketing.advice'],$expiry);
        $this->connection=new HermesAnalystConnection($raw['profile_url'],$credential,$raw['enabled']);
        ksort($raw);
        $this->configurationHash=hash('sha256',json_encode([$org,$raw],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    }

    public function assertAccepted(string $org):void
    {
        $this->admission->assertAllowed($org);
        $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
        if($this->acceptedUntil<=$now||$this->acceptedUntil>$now->modify('+30 days')){
            throw new DomainException('Hermes isolation and provider cost-control acceptance absent, expired or too long.');
        }
    }
}
