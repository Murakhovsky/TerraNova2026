<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use Domains\Property\Application\Contract\PropertyCanonicalRuntimeRepositoryInterface;
use Domains\Property\Application\Contract\PropertyInventoryCommandInterface;
use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Property\Contract\PropertyReferencePort;
use InvalidArgumentException;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class PropertyMutationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private PropertyCanonicalRuntimeService $runtime,
        private PropertyInventoryCommandInterface $inventory,
        private PropertyCanonicalRuntimeRepositoryInterface $repository,
        private PropertyReferencePort $properties,
    ) {}

    public function __invoke(PropertyMutationCommand $command): array
    {
        $org=$command->organizationId->value();
        $actor=(string)$command->actorId;
        $input=$command->input;

        if(in_array($command->operation,[PropertyMutationCommand::CREATE,PropertyMutationCommand::CREATE_INVENTORY,PropertyMutationCommand::RESERVE_INVENTORY],true)){
            $key=trim((string)$command->idempotencyKey);
            if($key==='')throw new InvalidArgumentException('X-Idempotency-Key is required.');
            $stable=$this->stableId($org,$command->operation,$key);

            if($command->operation===PropertyMutationCommand::CREATE){
                $input['asset_id']='PROP-'.$stable;
                $existing=$this->properties->getPropertyPresentation($org,$input['asset_id']);
                if($existing!==null)return $existing+['replayed'=>true];
            }elseif($command->operation===PropertyMutationCommand::CREATE_INVENTORY){
                $input['inventory_id']='INV-'.$stable;
                $existing=$this->properties->getInventorySnapshot($org,$input['inventory_id']);
                if($existing!==null)return $existing+['replayed'=>true];
            }else{
                $input['reservation_id']='RSV-'.$stable;
                $active=$this->repository->findActiveReservation($org,$this->requiredReference($command));
                if($active!==null){
                    if($active->reservationId!==$input['reservation_id']){
                        throw new InvalidArgumentException('Inventory item already has an active reservation.');
                    }
                    return [
                        'reservation_id'=>$active->reservationId,
                        'inventory'=>$this->properties->getInventorySnapshot($org,$this->requiredReference($command)),
                        'replayed'=>true,
                    ];
                }
            }
        }

        return match($command->operation){
            PropertyMutationCommand::CREATE=>$this->runtime->registerAsset($org,$input,$actor,$command->correlationId),
            PropertyMutationCommand::UPDATE=>$this->runtime->updateAsset($org,$this->requiredReference($command),$input,$actor,$command->correlationId),
            PropertyMutationCommand::CREATE_INVENTORY=>$this->runtime->createInventory($org,$this->requiredReference($command),$input,$actor,$command->correlationId),
            PropertyMutationCommand::CHANGE_INVENTORY_STATUS=>$this->inventory->changeStatus(
                $org,
                $this->requiredReference($command),
                trim((string)($input['status']??'')),
                ($reason=trim((string)($input['reason']??'')))!==''?$reason:null,
                $actor,
                $command->correlationId,
            ),
            PropertyMutationCommand::RESERVE_INVENTORY=>$this->inventory->reserve($org,$this->requiredReference($command),$input,$actor,$command->correlationId),
            default=>throw new InvalidArgumentException('Unsupported Property mutation.'),
        };
    }

    private function stableId(string $organizationId,string $operation,string $key): string
    {
        return strtoupper(substr(hash('sha256',$organizationId.':'.$operation.':'.$key),0,20));
    }

    private function requiredReference(PropertyMutationCommand $command): string
    {
        $reference=trim((string)$command->reference);
        if($reference==='')throw new InvalidArgumentException('Property reference is required.');
        return $reference;
    }
}
