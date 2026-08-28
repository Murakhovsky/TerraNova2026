<?php
declare(strict_types=1);

namespace Domains\Property\Application\UseCase;

use Domains\Property\Application\Contract\PropertyNotificationInterface;
use Domains\Property\Application\Contract\PropertyAnalyticsInterface;
use Domains\Property\Application\Contract\PropertySubmissionMediaInterface;
use Domains\Property\Application\Contract\PropertySubmissionInterface;
use Domains\Property\Application\Contract\PropertySubmissionRepositoryInterface;
use Throwable;

class PropertySubmissionService implements PropertySubmissionInterface
{
    private const SUCCESS_MESSAGE = 'Об’єкт прийнято на модерацію. Менеджер Terra Nova зв’яжеться з вами для уточнення деталей.';
    private const VALIDATION_MESSAGE = 'Заповніть контактні дані, місто, тип об’єкта та короткий опис.';
    private const ERROR_MESSAGE = 'Об’єкт не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.';

    public function __construct(
        private PropertySubmissionRepositoryInterface $submissions,
        private PropertySubmissionMediaInterface $mediaStorage,
        private ?PropertyNotificationInterface $notifications = null,
        private ?PropertyAnalyticsInterface $analytics = null,
    ) {
    }

    public function submit(array $input, string $sourcePage, array $files = []): array
    {
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return ['ok' => true, 'message' => self::SUCCESS_MESSAGE];
        }

        $ownerName = trim((string) ($input['owner_name'] ?? $input['full_name'] ?? ''));
        $ownerPhone = trim((string) ($input['owner_phone'] ?? $input['phone'] ?? ''));
        $ownerEmail = trim((string) ($input['owner_email'] ?? $input['email'] ?? ''));
        $propertyType = trim((string) ($input['property_type'] ?? ''));
        $city = trim((string) ($input['city'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));

        if ($ownerName === '' || ($ownerPhone === '' && $ownerEmail === '') || $propertyType === '' || $city === '' || $description === '') {
            return ['ok' => false, 'message' => self::VALIDATION_MESSAGE];
        }

        if ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Вкажіть коректний email або залиште поле порожнім.'];
        }

        try {
            $submissionId = $this->submissions->create([
                'submission_ref' => $this->submissionRef(),
                'source_type' => $this->sourceType((string) ($input['source_type'] ?? 'owner')),
                'deal_type' => $this->dealType((string) ($input['deal_type'] ?? 'sale')),
                'property_type' => $this->propertyType($propertyType),
                'title' => $this->nullableText($input['title'] ?? null, 220) ?: $this->fallbackTitle($propertyType, $city),
                'city' => $this->requiredText($city, 120),
                'region' => $this->nullableText($input['region'] ?? null, 120),
                'district' => $this->nullableText($input['district'] ?? null, 120),
                'address' => $this->nullableText($input['address'] ?? null, 255),
                'price_amount' => $this->decimalOrNull($input['price_amount'] ?? null),
                'price_currency' => $this->currency((string) ($input['price_currency'] ?? 'USD')),
                'area_total' => $this->decimalOrNull($input['area_total'] ?? null),
                'land_area' => $this->decimalOrNull($input['land_area'] ?? null),
                'rooms' => $this->decimalOrNull($input['rooms'] ?? null),
                'floor' => $this->positiveInt($input['floor'] ?? null),
                'floors' => $this->positiveInt($input['floors'] ?? null),
                'built_year' => $this->builtYear($input['built_year'] ?? null),
                'has_3d_tour' => isset($input['has_3d_tour']) ? 1 : 0,
                'media_links' => $this->nullableText($input['media_links'] ?? null, 2000),
                'description' => $this->requiredText($description, 5000),
                'features_text' => $this->nullableText($input['features_text'] ?? null, 3000),
                'owner_name' => $this->requiredText($ownerName, 160),
                'owner_phone' => $this->nullableText($ownerPhone, 50),
                'owner_email' => $this->nullableText($ownerEmail, 160),
                'preferred_contact' => $this->preferredContact((string) ($input['preferred_contact'] ?? 'any')),
                'source_page' => $this->nullableText($sourcePage, 255),
            ]);

            $this->recordPropertySubmit($submissionId, $sourcePage, $input);
            $this->notifications?->notifyPropertySubmission($submissionId);

            try {
                $stored = $this->mediaStorage->storeUploadedFiles($files, 'property_submission', $submissionId);

                if ($stored) {
                    return ['ok' => true, 'message' => self::SUCCESS_MESSAGE . ' Медіа додано до заявки.'];
                }
            } catch (Throwable $mediaError) {
                $this->logError('property-submission-media', $mediaError);

                return ['ok' => true, 'message' => self::SUCCESS_MESSAGE . ' Частину медіа не вдалося додати: перевірте тип і розмір файлів.'];
            }
        } catch (Throwable $e) {
            $this->logError('property-submission-exception', $e);

            return ['ok' => false, 'message' => self::ERROR_MESSAGE];
        }

        return ['ok' => true, 'message' => self::SUCCESS_MESSAGE];
    }

    private function recordPropertySubmit(int $submissionId, string $sourcePage, array $input): void
    {
        if ($submissionId <= 0) {
            return;
        }

        try {
            $query = [];
            $parts = parse_url($sourcePage);
            if (!empty($parts['query'])) {
                parse_str((string) $parts['query'], $query);
            }

            $this->analytics?->recordSubmission($submissionId, [
                'source_page' => mb_substr($sourcePage, 0, 255),
                'utm_source' => $this->nullableText($input['utm_source'] ?? $query['utm_source'] ?? null, 120),
                'utm_medium' => $this->nullableText($input['utm_medium'] ?? $query['utm_medium'] ?? null, 120),
                'utm_campaign' => $this->nullableText($input['utm_campaign'] ?? $query['utm_campaign'] ?? null, 160),
            ]);
        } catch (Throwable $e) {
            $this->logError('property-submit-analytics', $e);
        }
    }

    public function submissionForUser(int $id, array $user): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $email = mb_strtolower(trim((string) ($user['email'] ?? '')));
        if ($email === '') {
            return null;
        }

        $submission = $this->submissions->findForOwner($id, $email);

        if ($submission) {
            $submission['can_edit'] = $this->canEditSubmission($submission) ? 1 : 0;
        }

        return $submission ?: null;
    }

    public function updateForUser(int $id, array $user, array $input, array $files = []): array
    {
        $submission = $this->submissionForUser($id, $user);
        if (!$submission) {
            return ['ok' => false, 'message' => 'Заявку не знайдено або вона належить іншому користувачу.'];
        }

        if (!$this->canEditSubmission($submission)) {
            return ['ok' => false, 'message' => 'Цю заявку вже не можна редагувати з кабінету.'];
        }

        $ownerName = trim((string) ($input['owner_name'] ?? $submission['owner_name'] ?? ''));
        $ownerPhone = trim((string) ($input['owner_phone'] ?? $submission['owner_phone'] ?? ''));
        $ownerEmail = trim((string) ($input['owner_email'] ?? $submission['owner_email'] ?? ''));
        $propertyType = trim((string) ($input['property_type'] ?? $submission['property_type'] ?? ''));
        $city = trim((string) ($input['city'] ?? $submission['city'] ?? ''));
        $description = trim((string) ($input['description'] ?? $submission['description'] ?? ''));

        if ($ownerName === '' || ($ownerPhone === '' && $ownerEmail === '') || $propertyType === '' || $city === '' || $description === '') {
            return ['ok' => false, 'message' => self::VALIDATION_MESSAGE];
        }

        if ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Вкажіть коректний email або залиште поле порожнім.'];
        }

        $accountEmail = mb_strtolower(trim((string) ($user['email'] ?? '')));
        if ($accountEmail !== '' && mb_strtolower($ownerEmail) !== $accountEmail) {
            return ['ok' => false, 'message' => 'Email власника має збігатися з email вашого акаунта.'];
        }

        try {
            $this->submissions->update($id, [
                'source_type' => $this->sourceType((string) ($input['source_type'] ?? $submission['source_type'] ?? 'owner')),
                'deal_type' => $this->dealType((string) ($input['deal_type'] ?? $submission['deal_type'] ?? 'sale')),
                'property_type' => $this->propertyType($propertyType),
                'title' => $this->nullableText($input['title'] ?? $submission['title'] ?? null, 220) ?: $this->fallbackTitle($propertyType, $city),
                'city' => $this->requiredText($city, 120),
                'region' => $this->nullableText($input['region'] ?? $submission['region'] ?? null, 120),
                'district' => $this->nullableText($input['district'] ?? $submission['district'] ?? null, 120),
                'address' => $this->nullableText($input['address'] ?? $submission['address'] ?? null, 255),
                'price_amount' => $this->decimalOrNull($input['price_amount'] ?? $submission['price_amount'] ?? null),
                'price_currency' => $this->currency((string) ($input['price_currency'] ?? $submission['price_currency'] ?? 'USD')),
                'area_total' => $this->decimalOrNull($input['area_total'] ?? $submission['area_total'] ?? null),
                'land_area' => $this->decimalOrNull($input['land_area'] ?? $submission['land_area'] ?? null),
                'rooms' => $this->decimalOrNull($input['rooms'] ?? $submission['rooms'] ?? null),
                'floor' => $this->positiveInt($input['floor'] ?? $submission['floor'] ?? null),
                'floors' => $this->positiveInt($input['floors'] ?? $submission['floors'] ?? null),
                'built_year' => $this->builtYear($input['built_year'] ?? $submission['built_year'] ?? null),
                'has_3d_tour' => isset($input['has_3d_tour']) ? 1 : 0,
                'media_links' => $this->nullableText($input['media_links'] ?? $submission['media_links'] ?? null, 2000),
                'description' => $this->requiredText($description, 5000),
                'features_text' => $this->nullableText($input['features_text'] ?? $submission['features_text'] ?? null, 3000),
                'owner_name' => $this->requiredText($ownerName, 160),
                'owner_phone' => $this->nullableText($ownerPhone, 50),
                'owner_email' => $this->nullableText($ownerEmail, 160),
                'preferred_contact' => $this->preferredContact((string) ($input['preferred_contact'] ?? $submission['preferred_contact'] ?? 'any')),
            ]);

            try {
                $this->mediaStorage->storeUploadedFiles($files, 'property_submission', $id);
            } catch (Throwable $mediaError) {
                $this->logError('property-submission-update-media', $mediaError);

                return ['ok' => true, 'message' => 'Заявку оновлено, але частину медіа не вдалося додати.'];
            }
        } catch (Throwable $e) {
            $this->logError('property-submission-update', $e);

            return ['ok' => false, 'message' => self::ERROR_MESSAGE];
        }

        return ['ok' => true, 'message' => 'Заявку оновлено і повернуто в чергу модерації.'];
    }

    private function canEditSubmission(array $submission): bool
    {
        if (!empty($submission['property_id'])) {
            return false;
        }

        return in_array((string) ($submission['status'] ?? ''), ['draft', 'submitted', 'new', 'review', 'in_review', 'needs_changes'], true);
    }

    private function submissionRef(): string
    {
        return 'TNS-' . date('Ymd-His') . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    private function fallbackTitle(string $propertyType, string $city): string
    {
        return mb_substr(trim($propertyType . ' / ' . $city), 0, 220);
    }

    private function requiredText(string $value, int $limit): string
    {
        return mb_substr(trim($value), 0, $limit);
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function decimalOrNull(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '' || !is_numeric($value)) {
            return null;
        }

        return max(0, (float) $value);
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function builtYear(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $year = (int) $value;

        return $year >= 1800 && $year <= ((int) date('Y') + 2) ? $year : null;
    }

    private function dealType(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $types = ['sale', 'rent', 'investment'];

        return in_array($value, $types, true) ? $value : 'sale';
    }

    private function sourceType(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $types = ['owner', 'realtor', 'developer', 'partner', 'other'];

        return in_array($value, $types, true) ? $value : 'owner';
    }

    private function propertyType(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $types = ['apartment', 'house', 'cottage', 'land', 'commercial', 'new_building', 'other'];

        return in_array($value, $types, true) ? $value : 'other';
    }

    private function currency(string $value): string
    {
        $value = strtoupper(trim($value));
        $currencies = ['USD', 'EUR', 'UAH'];

        return in_array($value, $currencies, true) ? $value : 'USD';
    }

    private function preferredContact(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $types = ['phone', 'telegram', 'email', 'any'];

        return in_array($value, $types, true) ? $value : 'any';
    }

    private function logError(string $label, Throwable|string $error): void
    {
        $directory = BASE_PATH . '/tmp/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $message = is_string($error) ? $error : $error->getMessage();
        $entry = sprintf("[%s] %s: %s%s", date('Y-m-d H:i:s'), $label, $message, PHP_EOL);
        @file_put_contents($directory . '/frontend.log', $entry, FILE_APPEND);
    }
}
