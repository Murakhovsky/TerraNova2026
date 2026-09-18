<?php
declare(strict_types=1);

namespace App\Application\Property\Service;

use DateTimeImmutable;
use Domains\Property\Application\Contract\PropertyCanonicalRuntimeRepositoryInterface;
use Domains\Property\Application\Contract\PropertyInventoryCommandInterface;
use Domains\Property\Application\Contract\PropertyMutationReceiptInterface;
use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Property\Contract\PropertyReferencePort;
use InvalidArgumentException;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class PropertyWriteService
{
    public function __construct(
        private PropertyCanonicalRuntimeService $runtime,
        private PropertyInventoryCommandInterface $inventory,
        private PropertyCanonicalRuntimeRepositoryInterface $repository,
        private PropertyReferencePort $properties,
        private PropertyMutationReceiptInterface $receipts,
        private TransactionManagerInterface $transactions,
        private AuditRepositoryInterface $audit,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(OrganizationId $organizationId,int $actorId,string $correlationId,string $idempotencyKey,array $input):array
    {
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$idempotencyKey,$input):array{
            $org=$organizationId->value();
            $assetId='PROP-'.$this->stableId($org,'create',$idempotencyKey);
            $existing=$this->properties->getPropertyPresentation($org,$assetId);
            if($existing!==null)return $existing+['replayed'=>true];

            $input['asset_id']=$assetId;
            $result=$this->runtime->registerAsset($org,$input,(string)$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'property.create','property',$assetId,$idempotencyKey);
            return $result;
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(OrganizationId $organizationId,int $actorId,string $correlationId,string $reference,array $input):array
    {
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$reference,$input):array{
            $result=$this->runtime->updateAsset($organizationId->value(),$reference,$input,(string)$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'property.update','property',$reference,null);
            return $result;
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createInventory(OrganizationId $organizationId,int $actorId,string $correlationId,string $propertyReference,string $idempotencyKey,array $input):array
    {
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$propertyReference,$idempotencyKey,$input):array{
            $org=$organizationId->value();
            $inventoryId='INV-'.$this->stableId($org,'create_inventory',$idempotencyKey);
            $existing=$this->properties->getInventorySnapshot($org,$inventoryId);
            if($existing!==null)return $existing+['replayed'=>true];

            $input['inventory_id']=$inventoryId;
            $result=$this->runtime->createInventory($org,$propertyReference,$input,(string)$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'property.inventory.create','property_inventory',$inventoryId,$idempotencyKey);
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function changeInventoryStatus(
        OrganizationId $organizationId,
        int $actorId,
        string $correlationId,
        string $inventoryId,
        string $status,
        ?string $reason,
    ):array {
        if(trim($status)==='')throw new InvalidArgumentException('status is required.');

        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$inventoryId,$status,$reason):array{
            $result=$this->inventory->changeStatus(
                $organizationId->value(),$inventoryId,$status,$reason,(string)$actorId,$correlationId,
            );
            $this->appendAudit($organizationId,$actorId,$correlationId,'property.inventory.status_changed','property_inventory',$inventoryId,null,[
                'status'=>$status,'reason'=>$reason,
            ]);
            return $result;
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reserveInventory(
        OrganizationId $organizationId,
        int $actorId,
        string $correlationId,
        string $inventoryId,
        string $idempotencyKey,
        array $input,
    ):array {
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$inventoryId,$idempotencyKey,$input):array{
            $org=$organizationId->value();
            $reservationId='RSV-'.$this->stableId($org,'reserve_inventory',$idempotencyKey);
            $active=$this->repository->findActiveReservation($org,$inventoryId);
            if($active!==null){
                if($active->reservationId!==$reservationId){
                    throw new InvalidArgumentException('Inventory item already has an active reservation.');
                }
                return [
                    'reservation_id'=>$active->reservationId,
                    'inventory'=>$this->properties->getInventorySnapshot($org,$inventoryId),
                    'replayed'=>true,
                ];
            }

            $input['reservation_id']=$reservationId;
            $result=$this->inventory->reserve($org,$inventoryId,$input,(string)$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'property.inventory.reserved','property_inventory',$inventoryId,$idempotencyKey,[
                'reservation_id'=>$result['reservation_id']??$reservationId,
            ]);
            return $result;
        });
    }

    /** @param array<string,mixed> $data */
    private function appendAudit(
        OrganizationId $organizationId,
        int $actorId,
        string $correlationId,
        string $action,
        string $subjectType,
        string $subjectId,
        ?string $idempotencyKey,
        array $data=[],
    ):void {
        $this->audit->append(new AuditEntry(
            bin2hex(random_bytes(16)),
            $organizationId->value(),
            'property.mutation',
            'USER',
            (string)$actorId,
            $subjectType,
            $subjectId,
            null,
            [
                'action'=>$action,
                'idempotency_key_hash'=>$idempotencyKey!==null?hash('sha256',$idempotencyKey):null,
                ...$data,
            ],
            $correlationId,
            new DateTimeImmutable(),
        ));
    }

    private function stableId(string $organizationId,string $operation,string $key):string
    {
        return strtoupper(substr(hash('sha256',$organizationId.':'.$operation.':'.$key),0,20));
    }
}
