<?php
declare(strict_types=1);

namespace Infrastructure\Identity;

use Infrastructure\Persistence\Phalcon\Identity\Telegram\Message\UserMessages;
use Infrastructure\Persistence\Phalcon\Identity\Telegram\Person\AppUsers;
use Infrastructure\Persistence\Phalcon\Identity\Telegram\Person\UsersProgress;

final class TelegramUserService
{
    private AppUsers $user;

    public function __construct()
    {
        $this->user = new AppUsers();
    }

    public static function getUserProgress(int $userId): UsersProgress
    {
        return UsersProgress::findFirstOrCreate($userId);
    }

    public static function getUserMessages(int $userId): mixed
    {
        return UserMessages::find("user_id = '$userId' AND status = 'sent'");
    }

    public static function getUser(int $userId): AppUsers
    {
        return AppUsers::findFirstOrCreate($userId);
    }

    public function findByID(mixed $id): mixed
    {
        return AppUsers::findFirst($id);
    }

    public function assign(array $data): void
    {
        $this->user->assign($data);
    }

    public function save(): ?int
    {
        return $this->user->saveModel();
    }
}
