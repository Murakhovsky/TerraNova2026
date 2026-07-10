<?php
declare(strict_types=1);

namespace Modules\Frontend\Services;

use Common\Models\RealEstate\PropertySubmission;
use Common\Services\MediaStorageService;
use Throwable;

class PropertySubmissionService
{
    private const SUCCESS_MESSAGE = 'Об’єкт прийнято на модерацію. Менеджер Terra Nova зв’яжеться з вами для уточнення деталей.';
    private const VALIDATION_MESSAGE = 'Заповніть контактні дані, місто, тип об’єкта та короткий опис.';
    private const ERROR_MESSAGE = 'Об’єкт не вдалося зберегти. Спробуйте ще раз або напишіть нам напряму.';

    public function __construct(private MediaStorageService $mediaStorage)
    {
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
            $submission = new PropertySubmission();
            $submission->submission_ref = $this->submissionRef();
            $submission->status = 'new';
            $submission->source_type = $this->sourceType((string) ($input['source_type'] ?? 'owner'));
            $submission->deal_type = $this->dealType((string) ($input['deal_type'] ?? 'sale'));
            $submission->property_type = $this->propertyType($propertyType);
            $submission->title = $this->nullableText($input['title'] ?? null, 220) ?: $this->fallbackTitle($propertyType, $city);
            $submission->city = $this->requiredText($city, 120);
            $submission->region = $this->nullableText($input['region'] ?? null, 120);
            $submission->district = $this->nullableText($input['district'] ?? null, 120);
            $submission->address = $this->nullableText($input['address'] ?? null, 255);
            $submission->price_amount = $this->decimalOrNull($input['price_amount'] ?? null);
            $submission->price_currency = $this->currency((string) ($input['price_currency'] ?? 'USD'));
            $submission->area_total = $this->decimalOrNull($input['area_total'] ?? null);
            $submission->land_area = $this->decimalOrNull($input['land_area'] ?? null);
            $submission->rooms = $this->decimalOrNull($input['rooms'] ?? null);
            $submission->floor = $this->positiveInt($input['floor'] ?? null);
            $submission->floors = $this->positiveInt($input['floors'] ?? null);
            $submission->built_year = $this->builtYear($input['built_year'] ?? null);
            $submission->has_3d_tour = isset($input['has_3d_tour']) ? 1 : 0;
            $submission->media_links = $this->nullableText($input['media_links'] ?? null, 2000);
            $submission->description = $this->requiredText($description, 5000);
            $submission->features_text = $this->nullableText($input['features_text'] ?? null, 3000);
            $submission->owner_name = $this->requiredText($ownerName, 160);
            $submission->owner_phone = $this->nullableText($ownerPhone, 50);
            $submission->owner_email = $this->nullableText($ownerEmail, 160);
            $submission->preferred_contact = $this->preferredContact((string) ($input['preferred_contact'] ?? 'any'));
            $submission->source_page = $this->nullableText($sourcePage, 255);

            if (!$submission->save()) {
                $this->logError('property-submission-validation', implode('; ', $submission->getMessages()));

                return ['ok' => false, 'message' => self::ERROR_MESSAGE];
            }

            try {
                $stored = $this->mediaStorage->storeUploadedFiles($files, 'property_submission', (int) $submission->id);

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
