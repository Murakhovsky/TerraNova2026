<?php
declare(strict_types=1);

namespace Infrastructure\Security;

use Domains\Identity\Application\Contract\TelegramAccessPolicyInterface;
use Infrastructure\Platform\Persistence\Pdo\PdoConnection;

final readonly class TelegramAccessPolicy implements TelegramAccessPolicyInterface
{
    private const ACCESS = [
        'public' => [
            'menu_profile', 'menu_CRM', 'menu_search', 'menu_language', 'menu_catalog',
            'menu_adverts', 'menu_another', 'menu_myObjects', 'menu_advertsAll', 'menu_games',
        ],
        'registered' => [
            'menu_company', 'menu_myObjects', 'menu_myRequests', 'menu_showing', 'menu_tasks',
            'menu_archive', 'menu_objectsAll', 'menu_requestsAll', 'menu_settings', 'menu_wallet',
            'menu_top_up', 'menu_mining', 'menu_pay', 'menu_admin_panel',
        ],
        'paid' => [],
        'admin' => [],
    ];

    public function __construct(private PdoConnection $database)
    {
    }

    public function check(int $userId, string $capability): array
    {
        $status = $this->getStatus($userId);
        $levels = ['public'];
        if (in_array($status, ['registered', 'paid', 'admin'], true)) $levels[] = 'registered';
        if (in_array($status, ['paid', 'admin'], true)) $levels[] = 'paid';
        if ($status === 'admin') $levels[] = 'admin';

        $allowed = [];
        foreach ($levels as $level) $allowed = array_merge($allowed, self::ACCESS[$level]);
        if (in_array($capability, $allowed, true)) {
            return ['is_allow' => true, 'message' => $this->message('TG_ACCESS_IS_ALLOWED', 'Access is allowed.')];
        }

        $required = 'public';
        foreach (self::ACCESS as $level => $capabilities) {
            if (in_array($capability, $capabilities, true)) {
                $required = $level;
                break;
            }
        }
        $message = match ($required) {
            'registered' => $this->message('TG_NEED_TO_ADD_PROFILE', 'Complete your profile to continue.'),
            'paid' => $this->message('TG_NEED_TO_PAY', 'A paid account is required.'),
            'admin' => $this->message('TG_NEED_ADMIN_RIGHTS', 'Administrator rights are required.'),
            default => $this->message('TG_NO_PAGE_IN_LIST', 'This menu item is unavailable.'),
        };

        return ['is_allow' => false, 'message' => $message];
    }

    public function getStatus(int $userId): string
    {
        $user = $this->database->fetchOne(
            'SELECT status FROM person_users WHERE id = :id LIMIT 1',
            ['id' => $userId],
        );

        return trim((string) ($user['status'] ?? 'public')) ?: 'public';
    }

    private function message(string $constant, string $fallback): string
    {
        return defined($constant) ? (string) constant($constant) : $fallback;
    }
}
