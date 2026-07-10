<?php
declare(strict_types=1);

namespace Common\Services;

use Throwable;

class AuthService
{
    private const SESSION_KEY = 'tn_auth_user_id';

    public function __construct(private DatabaseService $database, private mixed $session)
    {
    }

    public function currentUser(): ?array
    {
        $userId = (int) ($this->session->get(self::SESSION_KEY) ?? 0);

        if ($userId <= 0) {
            return null;
        }

        return $this->database->fetchOne('
            SELECT id, email, full_name, phone, role, status, last_login_at, created_at
            FROM tn_users
            WHERE id = :id AND status = "active"
            LIMIT 1
        ', ['id' => $userId]);
    }

    public function register(array $input): array
    {
        $name = trim((string) ($input['full_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $phone = trim((string) ($input['phone'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $passwordRepeat = (string) ($input['password_repeat'] ?? '');
        $role = $this->role((string) ($input['role'] ?? 'buyer'));

        if ($name === '' || $email === '' || $password === '') {
            return ['ok' => false, 'message' => 'Заповніть ім’я, email і пароль.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Вкажіть коректний email.'];
        }

        if (mb_strlen($password) < 8) {
            return ['ok' => false, 'message' => 'Пароль має містити щонайменше 8 символів.'];
        }

        if ($password !== $passwordRepeat) {
            return ['ok' => false, 'message' => 'Паролі не збігаються.'];
        }

        try {
            if ($this->findByEmail($email)) {
                return ['ok' => false, 'message' => 'Користувач із таким email уже існує.'];
            }

            $statement = $this->database->connection()->prepare('
                INSERT INTO tn_users (email, password_hash, full_name, phone, role, status)
                VALUES (:email, :password_hash, :full_name, :phone, :role, "active")
            ');
            $statement->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'full_name' => mb_substr($name, 0, 160),
                'phone' => $phone !== '' ? mb_substr($phone, 0, 50) : null,
                'role' => $role,
            ]);

            $this->loginById((int) $this->database->connection()->lastInsertId());
        } catch (Throwable $e) {
            $this->logError('auth-register', $e);

            return ['ok' => false, 'message' => 'Реєстрацію не вдалося завершити. Спробуйте ще раз.'];
        }

        return ['ok' => true, 'message' => 'Акаунт створено.'];
    }

    public function login(array $input): array
    {
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            return ['ok' => false, 'message' => 'Вкажіть email і пароль.'];
        }

        $user = $this->findByEmail($email);
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return ['ok' => false, 'message' => 'Email або пароль неправильні.'];
        }

        if (($user['status'] ?? '') !== 'active') {
            return ['ok' => false, 'message' => 'Акаунт неактивний.'];
        }

        $this->loginById((int) $user['id']);

        return ['ok' => true, 'message' => 'Вхід виконано.'];
    }

    public function logout(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    public function cabinetData(array $user): array
    {
        $email = (string) $user['email'];

        return [
            'submissions' => $this->database->fetchAll('
                SELECT s.id, s.submission_ref, s.title, s.status, s.city, s.property_id, s.created_at,
                       p.slug AS property_slug, p.title AS property_title
                FROM tn_property_submissions s
                LEFT JOIN tn_properties p ON p.id = s.property_id
                WHERE s.owner_email = :email
                ORDER BY s.created_at DESC, s.id DESC
                LIMIT 20
            ', ['email' => $email]),
            'inbound_requests' => $this->database->fetchAll('
                SELECT l.id, l.role, l.deal_type, l.message, l.status, l.source_page, l.created_at,
                       p.slug AS property_slug, p.title AS property_title
                FROM tn_leads l
                LEFT JOIN tn_properties p ON p.id = l.property_id
                WHERE l.email = :email
                ORDER BY l.created_at DESC, l.id DESC
                LIMIT 20
            ', ['email' => $email]),
        ];
    }

    public function isManager(?array $user = null): bool
    {
        $user = $user ?? $this->currentUser();

        return $user && in_array((string) $user['role'], ['manager', 'admin'], true);
    }

    public function isAdmin(?array $user = null): bool
    {
        $user = $user ?? $this->currentUser();

        return $user && (string) $user['role'] === 'admin';
    }

    private function loginById(int $userId): void
    {
        if (method_exists($this->session, 'regenerateId')) {
            $this->session->regenerateId();
        }

        $this->session->set(self::SESSION_KEY, $userId);

        $statement = $this->database->connection()->prepare('
            UPDATE tn_users SET last_login_at = NOW() WHERE id = :id LIMIT 1
        ');
        $statement->execute(['id' => $userId]);
    }

    private function findByEmail(string $email): ?array
    {
        return $this->database->fetchOne('
            SELECT *
            FROM tn_users
            WHERE email = :email
            LIMIT 1
        ', ['email' => $email]);
    }

    private function role(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $roles = ['buyer', 'seller', 'investor', 'realtor', 'developer', 'partner'];

        return in_array($value, $roles, true) ? $value : 'buyer';
    }

    private function logError(string $label, Throwable $error): void
    {
        $directory = BASE_PATH . '/tmp/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $entry = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $label, $error->getMessage(), PHP_EOL);
        @file_put_contents($directory . '/frontend.log', $entry, FILE_APPEND);
    }
}
