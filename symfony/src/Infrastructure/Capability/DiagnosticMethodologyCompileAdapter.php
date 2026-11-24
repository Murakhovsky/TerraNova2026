<?php
declare(strict_types=1);

namespace App\Infrastructure\Capability;

use App\Application\Capability\Contract\CapabilityInvocationAdapterInterface;
use DomainException;
use Domains\Diagnostic\Application\Contract\MethodologyStudioRepositoryInterface;
use Domains\Diagnostic\Application\Service\DiagnosticMethodologyAccess;
use Domains\Diagnostic\Application\Service\MethodologyStudioService;
use Domains\Diagnostic\Methodology\Serialization\MethodologyPackSerializer;
use Kernel\Tenant\Model\TenantContext;

/** Read-only compilation of one tenant-owned version. No validate/publish/activate writes. */
final readonly class DiagnosticMethodologyCompileAdapter implements CapabilityInvocationAdapterInterface
{
    public function __construct(
        private MethodologyStudioService $studio,
        private MethodologyStudioRepositoryInterface $versions,
        private DiagnosticMethodologyAccess $access,
    ) {}

    public function binding(): string { return 'query:diagnostic.methodology.compile'; }

    public function invoke(TenantContext $actor, array $input, string $key, string $correlationId): mixed
    {
        $id=$input['pack_id']??null;
        $version=$input['methodology_version']??null;
        $user=$actor->userId()->value();
        if(count($input)!==2 || !is_string($id) || preg_match('/^[a-z][a-z0-9-]{1,190}$/D',$id)!==1
            || !is_string($version) || preg_match('/^(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,2})\.(?:0|[1-9][0-9]{0,2})$/D',$version)!==1
            || $version==='0.0.0' || !ctype_digit($user) || (int)$user<1) {
            throw new DomainException('Explicit pack and nonzero canonical methodology version are required.');
        }
        $org=$actor->organizationId()->value();
        if(!$this->access->allows($org,(int)$user,DiagnosticMethodologyAccess::EDIT)) {
            throw new DomainException('Methodology edit permission is required for draft compilation.');
        }
        $before=$this->versions->version($org,$id,$version);
        if(!$this->owns($before,$org,$id,$version) || !in_array($before['status']??null,['DRAFT','VALIDATING','READY','PUBLISHED'],true)) {
            throw new DomainException('Methodology version is unavailable.');
        }
        $compiled=$this->studio->compile($org,$id,$version);
        $hash=(new MethodologyPackSerializer())->hash($compiled->pack);
        [$major,$minor,$patch]=array_map('intval',explode('.',$version));
        $nativeVersion=$major*1000000+$minor*1000+$patch;
        $after=$this->versions->version($org,$id,$version);
        if(!$this->owns($after,$org,$id,$version) || $after!==$before
            || $compiled->pack->id!==$id || $compiled->pack->version!==$nativeVersion
            || !preg_match('/^[a-f0-9]{64}$/D',$hash)
            || (($before['status']??null)==='PUBLISHED' && ($before['content_hash']??null)!==$hash)) {
            throw new DomainException('Methodology compilation identity or version changed; reconcile before retry.');
        }
        return ['pack_id'=>$id,'methodology_version'=>$version,'runtime_version'=>$nativeVersion,
            'status'=>$before['status'],'content_hash'=>$hash];
    }

    private function owns(?array $row,string $org,string $id,string $version): bool
    {
        return $row!==null && ($row['organization_id']??null)===$org && ($row['pack_id']??null)===$id
            && ($row['methodology_version']??null)===$version;
    }
}
