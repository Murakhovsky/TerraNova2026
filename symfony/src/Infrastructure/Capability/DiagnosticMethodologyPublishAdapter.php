<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use DomainException;
use Domains\Diagnostic\Application\Contract\DiagnosticPackRepositoryInterface;
use Domains\Diagnostic\Application\Contract\MethodologyStudioRepositoryInterface;
use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Domains\Diagnostic\Methodology\Serialization\MethodologyPackSerializer;
use Domains\Diagnostic\Model\DiagnosticPackStatus;
use Kernel\Tenant\Model\TenantContext;

/** Publish exactly one reviewed fingerprint; never reactivate a version on replay. */
final readonly class DiagnosticMethodologyPublishAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(
        private MethodologyStudioService $studio,
        private MethodologyStudioRepositoryInterface $versions,
        private DiagnosticPackRepositoryInterface $native,
        private DiagnosticMethodologyAccess $access,
    ) {}

    public function binding(): string { return 'command:diagnostic.methodology.publish'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $id=$input['pack_id']??null;
        $version=$input['methodology_version']??null;
        $expected=$input['expected_content_hash']??null;
        $user=$actor->userId()->value();
        if(count($input)!==3 || !is_string($id) || preg_match('/^[a-z][a-z0-9-]{1,99}$/D',$id)!==1
            || !is_string($version) || preg_match('/^(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,2})$/D',$version)!==1
            || $version==='0.0.0' || !is_string($expected) || preg_match('/^[a-f0-9]{64}$/D',$expected)!==1
            || !ctype_digit($user) || (int)$user<1 || trim($key)==='') {
            throw new DomainException('Exact methodology version, reviewed fingerprint and authenticated request are required.');
        }
        $org=$actor->organizationId()->value();
        if(!$this->access->allows($org,(int)$user,DiagnosticMethodologyAccess::PUBLISH)) {
            throw new DomainException('Methodology publication permission is required.');
        }
        [$major,$minor,$patch]=array_map('intval',explode('.',$version));
        $nativeVersion=$major*1000000+$minor*1000+$patch;

        return $this->versions->withMutation($org,function () use ($org,$user,$id,$version,$expected,$nativeVersion): array {
            // Check authorization again after waiting on the tenant mutation lock.
            if(!$this->access->allows($org,(int)$user,DiagnosticMethodologyAccess::PUBLISH)) {
                throw new DomainException('Methodology publication permission was revoked.');
            }
            $before=$this->versions->version($org,$id,$version);
            if(!$this->owns($before,$org,$id,$version) || !in_array($before['status']??null,['DRAFT','VALIDATING','READY','PUBLISHED'],true)) {
                throw new DomainException('Publishable methodology version is unavailable.');
            }
            $replayed=$before['status']==='PUBLISHED';
            if($replayed) {
                if(($before['content_hash']??null)!==$expected) throw new DomainException('Published methodology fingerprint differs.');
            } else {
                $compiled=$this->studio->compile($org,$id,$version);
                if($compiled->pack->id!==$id || $compiled->pack->version!==$nativeVersion
                    || !hash_equals($expected,(new MethodologyPackSerializer())->hash($compiled->pack))) {
                    throw new DomainException('Methodology changed since review; compile and review again.');
                }
                // The existing Studio writer validates, runs scenarios, publishes, activates and audits.
                // Its nested mutation transaction joins this outer lock.
                $this->studio->publish($org,$id,$version,$user);
            }
            $after=$this->versions->version($org,$id,$version);
            $pack=$this->versions->pack($org,$id);
            $native=$this->native->get($org,$id,$nativeVersion);
            $active=$pack!==null && (int)($pack['active_version']??0)===$nativeVersion
                && ($pack['active_methodology_version']??null)===$version;
            if(!$this->owns($after,$org,$id,$version) || ($after['status']??null)!=='PUBLISHED'
                || ($after['content_hash']??null)!==$expected || $native===null
                || $native->id()!==$id || $native->version()!==$nativeVersion
                || $native->status()!==DiagnosticPackStatus::Published || $native->contentHash()!==$expected
                || $pack===null || ($pack['pack_id']??null)!==$id || (!$replayed && !$active)) {
                throw new DomainException('Native methodology publication readback did not verify; transaction rejected.');
            }
            return ['pack_id'=>$id,'methodology_version'=>$version,'runtime_version'=>$nativeVersion,
                'status'=>'PUBLISHED','content_hash'=>$expected,'active'=>$active,'replayed'=>$replayed];
        });
    }

    private function owns(?array $row,string $org,string $id,string $version): bool
    {
        return $row!==null && ($row['organization_id']??null)===$org && ($row['pack_id']??null)===$id
            && ($row['methodology_version']??null)===$version;
    }
}
