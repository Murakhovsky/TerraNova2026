<?php
declare(strict_types=1);

use Domains\Property\Application\Contract\PropertyNotificationInterface;
use Domains\Property\Application\Contract\PropertyAnalyticsInterface;
use Domains\Property\Application\Contract\PropertySubmissionMediaInterface;
use Domains\Property\Application\Contract\PropertySubmissionRepositoryInterface;
use Domains\Property\Application\UseCase\PropertySubmissionService;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$stored = [];
$events = [];
$repository = new class($stored, $events) implements PropertySubmissionRepositoryInterface {
    public function __construct(private array &$stored, private array &$events) {}
    public function create(array $submission): int
    {
        $this->stored[41] = ['id' => 41, 'property_id' => null, 'status' => 'new'] + $submission;
        return 41;
    }
    public function findForOwner(int $id, string $email): ?array
    {
        $item = $this->stored[$id] ?? null;
        return $item && mb_strtolower((string) $item['owner_email']) === $email ? $item : null;
    }
    public function update(int $id, array $submission): void
    {
        $this->stored[$id] = ($this->stored[$id] ?? []) + ['id' => $id];
        $this->stored[$id] = array_replace($this->stored[$id], $submission, ['status' => 'submitted']);
    }
};

$mediaCalls = [];
$media = new class($mediaCalls) implements PropertySubmissionMediaInterface {
    public function __construct(private array &$calls) {}
    public function storeUploadedFiles(array $files, string $entityType, int $entityId): array
    {
        $this->calls[] = [$files, $entityType, $entityId];
        return $files;
    }
};

$notifications = [];
$notifier = new class($notifications) implements PropertyNotificationInterface {
    public function __construct(private array &$notifications) {}
    public function notifyPropertySubmission(int $submissionId): void { $this->notifications[] = ['submission', $submissionId]; }
    public function notifySubmissionStatus(int $submissionId, string $status, string $note = '', ?int $propertyId = null): void {}
    public function notifyPropertyStatus(int $propertyId, string $status, string $note = ''): void {}
    public function notifyPresentationShared(int $propertyId, ?int $userId, string $channel, string $variant): void {}
};

$analytics = new class($events) implements PropertyAnalyticsInterface {
    public function __construct(private array &$events) {}
    public function recordSubmission(int $submissionId, array $context): void
    {
        $this->events[] = [$submissionId, $context];
    }
    public function recordView(int $propertyId, array $context): void {}
    public function recordPresentation(string $eventType, ?int $propertyId, ?int $entityId, ?int $userId, string $sourcePage, array $payload): void {}
};

$service = new PropertySubmissionService($repository, $media, $notifier, $analytics);
$invalid = $service->submit(['owner_name' => ''], '/submit');
if (($invalid['ok'] ?? true) !== false || $stored !== []) {
    throw new RuntimeException('Invalid property submission reached persistence.');
}

$result = $service->submit([
    'owner_name' => 'Олена Тест',
    'owner_phone' => '+380000000000',
    'owner_email' => 'owner@example.test',
    'property_type' => 'apartment',
    'city' => 'Львів',
    'description' => 'Тестова квартира',
    'utm_source' => 'smoke',
], '/submit?utm_campaign=property', [['name' => 'photo.jpg']]);

if (($result['ok'] ?? false) !== true || !isset($stored[41])) {
    throw new RuntimeException('Valid property submission was not persisted.');
}
if (($stored[41]['property_type'] ?? null) !== 'apartment' || ($stored[41]['owner_email'] ?? null) !== 'owner@example.test') {
    throw new RuntimeException('Property submission normalization failed.');
}
if (($events[0][0] ?? null) !== 41 || ($events[0][1]['utm_source'] ?? null) !== 'smoke') {
    throw new RuntimeException('Property submission analytics event was not recorded.');
}
if (($mediaCalls[0][1] ?? null) !== 'property_submission' || ($mediaCalls[0][2] ?? null) !== 41) {
    throw new RuntimeException('Property submission media port was not called.');
}
if ($notifications !== [['submission', 41]]) {
    throw new RuntimeException('Property submission notification port was not called.');
}

$updated = $service->updateForUser(41, ['email' => 'owner@example.test'], [
    'owner_name' => 'Олена Тест',
    'owner_phone' => '+380000000000',
    'owner_email' => 'owner@example.test',
    'property_type' => 'house',
    'city' => 'Львів',
    'description' => 'Оновлений опис',
]);
if (($updated['ok'] ?? false) !== true || ($stored[41]['property_type'] ?? null) !== 'house' || ($stored[41]['status'] ?? null) !== 'submitted') {
    throw new RuntimeException('Owner property submission update failed.');
}

echo "Property submission use case passed.\n";
