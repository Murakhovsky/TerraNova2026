<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Property;

use Domains\Property\Application\Contract\PropertyMutationReceiptInterface;
use InvalidArgumentException;
use PDO;

final readonly class PdoPropertyMutationReceipt implements PropertyMutationReceiptInterface
{
    public function __construct(private PDO $connection) {}

    public function claim(
        string $organizationId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
    ): bool {
        $entityType='property.'.mb_substr($operation,0,80);
        $reference='idem:'.hash('sha256',$idempotencyKey);
        $externalId=hash('sha256',$idempotencyKey.'|'.$fingerprint);

        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO cos_external_references '
            .'(organization_id,provider,entity_type,external_id,cos_reference,last_synced_at) '
            .'VALUES(:organization_id,"cos_api",:entity_type,:external_id,:reference,NOW(6))'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'entity_type'=>$entityType,
            'external_id'=>$externalId,
            'reference'=>$reference,
        ]);
        if($statement->rowCount()===1)return true;

        $lookup=$this->connection->prepare(
            'SELECT external_id FROM cos_external_references '
            .'WHERE organization_id=:organization_id AND provider="cos_api" '
            .'AND entity_type=:entity_type AND cos_reference=:reference LIMIT 1'
        );
        $lookup->execute([
            'organization_id'=>$organizationId,
            'entity_type'=>$entityType,
            'reference'=>$reference,
        ]);
        $existing=$lookup->fetchColumn();
        if($existing===false){
            throw new InvalidArgumentException('Property idempotency claim could not be resolved.');
        }
        if(!hash_equals((string)$existing,$externalId)){
            throw new InvalidArgumentException('Idempotency key was reused with a different Property payload.');
        }
        return false;
    }
}
