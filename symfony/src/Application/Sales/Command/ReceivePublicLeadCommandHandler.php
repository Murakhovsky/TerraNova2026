<?php

declare(strict_types=1);

namespace App\Application\Sales\Command;

use Domains\Sales\Application\Contract\SalesWriteServiceFactoryInterface;
use Kernel\Application\Command\CommandHandlerInterface;

final readonly class ReceivePublicLeadCommandHandler implements CommandHandlerInterface
{
    public function __construct(private SalesWriteServiceFactoryInterface $writes)
    {
    }

    public function __invoke(ReceivePublicLeadCommand $command): SalesMutationResult
    {
        $result = $this->writes
            ->forOrganization($command->organizationId->value())
            ->receivePublicLead($command->input, $command->sourcePage);

        return $result->ok
            ? SalesMutationResult::success($result->code, $result->data)
            : SalesMutationResult::failure(
                $result->code,
                match ($result->code) {
                    'contact_required' => 'Вкажіть ім’я та телефон або email.',
                    'invalid_email' => 'Перевірте email.',
                    default => 'Заявку не вдалося зберегти.',
                },
                $result->data,
            );
    }
}
