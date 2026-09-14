<?php
declare(strict_types=1);

namespace Interfaces\Web\Service;

use Domains\Sales\Application\UseCase\ReceivePublicLead;
use Throwable;

/** @deprecated Compatibility facade for legacy frontend controllers. */
readonly class InboundRequestService
{
    private const MESSAGES = [
        'accepted' => 'Заявку збережено. Менеджер Terra Nova отримає її в CRM.',
        'contact_required' => 'Заповніть імʼя та хоча б один контакт.',
        'invalid_email' => 'Вкажіть коректний email або залиште поле порожнім.',
        'failed' => 'Заявку не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.',
    ];

    public function __construct(private ReceivePublicLead $receivePublicLead)
    {
    }

    /** @return array{ok:bool,message:string} */
    public function submit(array $input, string $sourcePage): array
    {
        try {
            $result = $this->receivePublicLead->execute($input, $sourcePage);

            return ['ok' => $result->ok, 'message' => self::MESSAGES[$result->code] ?? self::MESSAGES['failed']];
        } catch (Throwable $exception) {
            error_log('Public lead intake failed: ' . $exception->getMessage());

            return ['ok' => false, 'message' => self::MESSAGES['failed']];
        }
    }
}
