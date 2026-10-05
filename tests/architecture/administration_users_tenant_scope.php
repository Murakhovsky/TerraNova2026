<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $fullPath = $root.'/'.$path;
    if (!is_file($fullPath)) {
        throw new RuntimeException('Missing tenant-scope contract file: '.$path);
    }

    return (string) file_get_contents($fullPath);
};

$interface = $read('app/Domains/Identity/Application/Contract/AdministrationServiceInterface.php');
$service = $read('app/Domains/Identity/Infrastructure/ReadModel/MySql/AdminDashboardService.php');
$membership = $read('app/Domains/Identity/Infrastructure/ReadModel/MySql/MembershipSynchronizedAdminDashboardService.php');
$controller = $read('symfony/src/Web/Administration/AdministrationUsersController.php');
$query = $read('symfony/src/Application/Administration/Query/GetAdministrationUsersQuery.php');
$create = $read('symfony/src/Application/Administration/Command/CreateAdministrationUserCommand.php');
$update = $read('symfony/src/Application/Administration/Command/UpdateAdministrationUserCommand.php');

foreach ([
    'users(array $filters, string $organizationId)',
    'userStats(string $organizationId)',
    'createUser(array $input, string $organizationId)',
    'updateUser(int $id, array $input, string $organizationId',
] as $needle) {
    if (!str_contains($interface, $needle)) {
        throw new RuntimeException('Users administration contract is not tenant-scoped: '.$needle);
    }
}

foreach ([
    "organization_id = :organization_id",
    "INSERT INTO tn_users (organization_id",
    "WHERE id = :id AND organization_id = :organization_id",
] as $needle) {
    if (!str_contains($service, $needle)) {
        throw new RuntimeException('Users persistence is missing tenant boundary: '.$needle);
    }
}

foreach ([
    "email = :email AND organization_id = :organization_id",
    "parent::createUser($input, $organizationId)",
    "parent::updateUser($id, $input, $organizationId, $actor)",
] as $needle) {
    if (!str_contains($membership, $needle)) {
        throw new RuntimeException('Membership synchronization is missing tenant context: '.$needle);
    }
}

if (substr_count($controller, '$tenant->organizationId()->value()') < 3) {
    throw new RuntimeException('Users controller must propagate tenant organization to read/create/update operations.');
}

foreach ([$query, $create, $update] as $message) {
    if (!str_contains($message, 'public string $organizationId')) {
        throw new RuntimeException('Users CQRS message is missing required organizationId.');
    }
}

echo "Administration users tenant-scope contract passed.\n";
