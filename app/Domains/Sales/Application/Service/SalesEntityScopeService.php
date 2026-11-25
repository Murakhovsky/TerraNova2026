<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Service;

use DomainException;
use Domains\Sales\Application\Contract\SalesWorkspaceScopeInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;

/** Live object authorization; no request-supplied organization or implicit tenant scope. */
final readonly class SalesEntityScopeService
{
    public function __construct(private SalesWorkspaceScopeInterface $scope,private SalesWorkspaceReadModelInterface $read) {}

    public function resolve(string $org,int $actor,string $mode='self',?int $owner=null):?int
    {
        return $this->scope->resolve($org,$actor,$mode,$owner);
    }

    public function requireEntity(string $org,int $actor,string $kind,int $id):void
    {
        $this->scope->resolve($org,$actor,'self',null);
        if ($id<1||!in_array($kind,['lead','deal'],true)) throw new DomainException('Sales entity unavailable.');
        $row=$kind==='lead'?$this->read->lead($org,$id):$this->read->deal($org,$id);
        if ($row===null||(string)($row['organization_id']??'')!==$org||(int)($row['id']??0)!==$id) {
            throw new DomainException('Sales entity unavailable.');
        }
        $owner=(int)($row['assigned_user_id']??0);
        try {
            if ($owner<1) $this->scope->resolve($org,$actor,'tenant',null);
            else $this->scope->resolve($org,$actor,'owner',$owner);
        } catch (DomainException) {
            // A director may access records of inactive/removed owners, but a team lead may not.
            try {$this->scope->resolve($org,$actor,'tenant',null);}
            catch (DomainException) {throw new DomainException('Sales entity unavailable.');}
        }
        if ($kind==='lead' && (int)($row['client_case_id']??0)>0) {
            // Existing lead detail includes linked opportunity and communication data.
            $this->requireEntity($org,$actor,'deal',(int)$row['client_case_id']);
        }
    }
}
