<?php
declare(strict_types=1);

namespace Domains\RealEstate\Infrastructure\Persistence\MySql;

use Domains\RealEstate\Application\Contract\RealEstateMutationReceiptInterface;
use InvalidArgumentException;
use PDO;

final readonly class MysqlRealEstateMutationReceipt implements RealEstateMutationReceiptInterface
{
    public function __construct(private PDO $connection) {}

    public function claim(
        string $organizationId,
        string $operation,
        string $idempotencyKey,
        string $fingerprint,
    ): bool {
        $statement=$this->connection->prepare(
            'INSERT IGNORE INTO tn_real_estate_operation_receipts '
            .'(organization_id,operation_type,idempotency_key,payload_fingerprint) '
            .'VALUES(:organization_id,:operation_type,:idempotency_key,:payload_fingerprint)'
        );
        $statement->execute([
            'organization_id'=>$organizationId,
            'operation_type'=>$operation,
            'idempotency_key'=>$idempotencyKey,
            'payload_fingerprint'=>$fingerprint,
        ]);
        if($statement->rowCount()===1)return true;

        $lookup=$this->connection->prepare(
            'SELECT payload_fingerprint FROM tn_real_estate_operation_receipts '
            .'WHERE organization_id=:organization_id AND operation_type=:operation_type '
            .'AND idempotency_key=:idempotency_key LIMIT 1'
        );
        $lookup->execute([
            'organization_id'=>$organizationId,
            'operation_type'=>$operation,
            'idempotency_key'=>$idempotencyKey,
        ]);
        $existing=$lookup->fetchColumn();
        if($existing===false){
            throw new InvalidArgumentException('RealEstate idempotency claim could not be resolved.');
        }
        if(!hash_equals((string)$existing,$fingerprint)){
            throw new InvalidArgumentException('Idempotency key was reused with a different RealEstate payload.');
        }
        return false;
    }
}
