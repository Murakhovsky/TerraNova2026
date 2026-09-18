<?php
declare(strict_types=1);

namespace App\Application\Property\Command;

use Domains\Property\Application\Contract\PropertyInventoryCommandInterface;
use Domains\Property\Application\Service\PropertyCanonicalRuntimeService;
use Domains\Property\Contract\PropertyReferencePort;
use Infrastructure\Platform\Persistence\ExternalReferenceStoreInterface;
use InvalidArgumentException;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class PropertyMutationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private PropertyCanonicalRuntimeService $runtime,
        private PropertyInventoryCommandInterface $inventory,
        private PropertyReferencePort $properties,
        private ExternalReferenceStoreInterface $idempotency,
    ) {}

    public function __invoke(PropertyMutationCommand $command): array
    {
        $org=$command->organizationId->value();
        $actor=(string)$command->actorId;

        if(in_array($command->operation,[PropertyMutationCommand::CREATE,PropertyMutationCommand::CREATE_INVENTORY,PropertyMutationCommand::RESERVE_INVENTORY],true)){
            if(trim((string)$command->idempotencyKey)==='')throw new InvalidArgumentException('X-Idempotency-Key is required.');
            $entity='property.' . $command->operation;
            $existing=$this->idempotency->find($org,'cos_api',$entity,(string)$command->idempotencyKey);
            if($existing!==null){
                return $this->replay($command,$existing);
            }
        }

        $result=match($command->operation){
            PropertyMutationCommand::CREATE=>$this->runtime->registerAsset($org,$command->input,$actor,$command->correlationId),
            PropertyMutationCommand::UPDATE=>$this->runtime->updateAsset($org,$this->requiredReference($command),$command->input,$actor,$command->correlationId),
            PropertyMutationCommand::CREATE_INVENTORY=>$this->runtime->createInventory($org,$this->requiredReference($command),$command->input,$actor,$command->correlationId),
            PropertyMutationCommand::CHANGE_INVENTORY_STATUS=>$this->inventory->changeStatus(
                $org,
                $this->requiredReference($command),
                trim((string)($command->input['status']??'')),
                ($reason=trim((string)($command->input['reason']??'')))!==''?$reason:null,
                $actor,
                $command->correlationId,
            ),
            PropertyMutationCommand::RESERVE_INVENTORY=>$this->inventory->reserve($org,$this->requiredReference($command),$command->input,$actor,$command->correlationId),
            default=>throw new InvalidArgumentException('Unsupported Property mutation.'),
        };

        if(trim((string)$command->idempotencyKey)!==''){
            $externalId=$this->resultIdentity($command,$result);
            if($externalId!==null){
                $this->idempotency->put($org,'cos_api','property.'.$command->operation,$externalId,(string)$command->idempotencyKey);
            }
        }
        return $result;
    }

    private function replay(PropertyMutationCommand $command,string $externalId): array
    {
        $org=$command->organizationId->value();
        return match($command->operation){
            PropertyMutationCommand::CREATE=>$this->properties->getPropertyPresentation($org,$externalId)??['asset_id'=>$externalId,'replayed'=>true],
            PropertyMutationCommand::CREATE_INVENTORY=>$this->properties->getInventorySnapshot($org,$externalId)??['inventory_id'=>$externalId,'replayed'=>true],
            PropertyMutationCommand::RESERVE_INVENTORY=>[
                'reservation_id'=>$externalId,
                'inventory'=>$this->properties->getInventorySnapshot($org,$this->requiredReference($command)),
                'replayed'=>true,
            ],
            default=>['id'=>$externalId,'replayed'=>true],
        };
    }

    private function resultIdentity(PropertyMutationCommand $command,array $result): ?string
    {
        return match($command->operation){
            PropertyMutationCommand::CREATE=>(string)($result['asset']['asset_id']??$result['asset_id']??'') ?: null,
            PropertyMutationCommand::CREATE_INVENTORY=>(string)($result['inventory_id']??'') ?: null,
            PropertyMutationCommand::RESERVE_INVENTORY=>(string)($result['reservation_id']??'') ?: null,
            default=>null,
        };
    }

    private function requiredReference(PropertyMutationCommand $command): string
    {
        $reference=trim((string)$command->reference);
        if($reference==='')throw new InvalidArgumentException('Property reference is required.');
        return $reference;
    }
}
