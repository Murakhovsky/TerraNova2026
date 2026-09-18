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
            if(!$this->receipts->claim($org,'create',$idempotencyKey,$this->fingerprint([
                'asset_id'=>$assetId,'input'=>$input,
            ]))){
                return ($this->properties->getPropertyPresentation($org,$assetId)
                    ?? throw new InvalidArgumentException('Property idempotency receipt exists but Property was not found.'))
                    + ['replayed'=>true];
            }

            $input['asset_id']=$assetId;
            $result=$this->runtime->registerAsset($org,$input,(string)$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'property.create','property',$assetId,$idempotencyKey);
            return $result;
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function update(OrganizationId $organizationId,int $actorId,string $correlationId,string $reference,string $idempotencyKey,array $input):array
    {
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$reference,$idempotencyKey,$input):array{
            $org=$organizationId->value();
            if(!$this->receipts->claim($org,'update',$idempotencyKey,$this->fingerprint([
                'reference'=>$reference,'input'=>$input,
            ]))){
                return ($this->properties->getPropertyPresentation($org,$reference)
                    ?? throw new InvalidArgumentException('Property idempotency receipt exists but Property was not found.'))
                    + ['replayed'=>true];
            }
            $result=$this->runtime->updateAsset($org,$reference,$input,(string)$actorId,$correlationId);
            $this->appendAudit($organizationId,$actorId,$correlationId,'property.update','property',$reference,$idempotencyKey);
            return $result;
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createInventory(OrganizationId $organizationId,int $actorId,string $correlationId,string $propertyReference,string $idempotencyKey,array $input):array
    {
        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$propertyReference,$idempotencyKey,$input):array{
            $org=$organizationId->value();
            $inventoryId='INV-'.$this->stableId($org,'create_inventory',$idempotencyKey);
            if(!$this->receipts->claim($org,'create_inventory',$idempotencyKey,$this->fingerprint([
                'property_reference'=>$propertyReference,'inventory_id'=>$inventoryId,'input'=>$input,
            ]))){
                return ($this->properties->getInventorySnapshot($org,$inventoryId)
                    ?? throw new InvalidArgumentException('Inventory idempotency receipt exists but Inventory was not found.'))
                    + ['replayed'=>true];
            }

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
        string $idempotencyKey,
    ):array {
        if(trim($status)==='')throw new InvalidArgumentException('status is required.');

        return $this->transactions->transactional(function()use($organizationId,$actorId,$correlationId,$inventoryId,$status,$reason,$idempotencyKey):array{
            $org=$organizationId->value();
            if(!$this->receipts->claim($org,'inventory_status',$idempotencyKey,$this->fingerprint([
                'inventory_id'=>$inventoryId,'status'=>$status,'reason'=>$reason,
            ]))){
                return ($this->properties->getInventorySnapshot($org,$inventoryId)
                    ?? throw new InvalidArgumentException('Inventory idempotency receipt exists but Inventory was not found.'))
                    + ['replayed'=>true];
            }
            $result=$this->inventory->changeStatus(
                $org,$inventoryId,$status,$reason,(string)$actorId,$correlationId,
            );
            $this->appendAudit($organizationId,$actorId,$correlationId,'property.inventory.status_changed','property_inventory',$inventoryId,$idempotencyKey,[
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
            if(!$this->receipts->claim($org,'reserve_inventory',$idempotencyKey,$this->fingerprint([
                'inventory_id'=>$inventoryId,'reservation_id'=>$reservationId,'input'=>$input,
            ]))){
                $active=$this->repository->findActiveReservation($org,$inventoryId);
                if($active===null||$active->reservationId!==$reservationId){
                    throw new InvalidArgumentException('Reservation idempotency receipt exists but reservation state does not match.');
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

    /** @param array<string,mixed> $value */
    private function fingerprint(array $value):string
    {
        $normalize=function(mixed $item)use(&$normalize):mixed{
            if(!is_array($item))return $item;
            if(array_is_list($item))return array_map($normalize,$item);
            ksort($item,SORT_STRING);
            foreach($item as $key=>$nested)$item[$key]=$normalize($nested);
            return $item;
        };
        return hash('sha256',(string)json_encode(
            $normalize($value),
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function stableId(string $organizationId,string $operation,string $key):string
    {
        return strtoupper(substr(hash('sha256',$organizationId.':'.$operation.':'.$key),0,20));
    }
}
