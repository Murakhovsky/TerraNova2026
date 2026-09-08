<?php
declare(strict_types=1);
namespace Domains\Diagnostic\Application\Service;
use PDO;
final readonly class DiagnosticMethodologyAccess
{
 public const VIEW='diagnostic.methodology.view',EDIT='diagnostic.methodology.edit',PUBLISH='diagnostic.methodology.publish';
 public function __construct(private PDO $database){}
 public function allows(string $organizationId,int $userId,string $permission):bool{$override=$this->database->prepare('SELECT allowed FROM diagnostic_user_permissions WHERE organization_id=:org AND user_id=:user AND permission=:permission');$override->execute(['org'=>$organizationId,'user'=>$userId,'permission'=>$permission]);$value=$override->fetchColumn();if($value!==false)return(bool)$value;$statement=$this->database->prepare('SELECT 1 FROM cos_organization_memberships membership JOIN diagnostic_role_permissions permission ON permission.role=membership.role WHERE membership.organization_id=:org AND membership.user_id=:user AND membership.status="ACTIVE" AND permission.permission=:permission LIMIT 1');$statement->execute(['org'=>$organizationId,'user'=>$userId,'permission'=>$permission]);return $statement->fetchColumn()!==false;}
}
