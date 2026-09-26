<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) throw new RuntimeException('Missing OperationalGrid artifact: ' . $path);
    $content = file_get_contents($full);
    if ($content === false) throw new RuntimeException('Unable to read: ' . $path);
    return $content;
};

$grid = $read('app/Interfaces/Web/View/components/ui/operational_grid.phtml');
$cos = $read('symfony/templates/experience/system/control_center.html.twig');
$users = $read('symfony/templates/experience/administration/users.html.twig');
$clientCaseItem = $read('symfony/templates/components/client_case/client_case_collection_item.html.twig');
$css = $read('frontend/styles/canonical-components.css');

foreach ([
    'tn-ui-operational-grid',
    'tn-ui-data-table',
    '$rowActions',
    "'kind'] ?? 'link'",
    "'hidden'] ?? null",
    "'_form'",
    "['field', 'fields']",
    '$editableFields',
    "'kind'] ?? 'link'",
    "'submit'",
    'type="submit" form="<?php echo $h($target); ?>"',
    'minlength=',
    'method="post"',
    'status_badge',
    "components/ui/stage",
    "=== 'stage'",
] as $marker) {
    if (!str_contains($grid, $marker)) {
        throw new RuntimeException('OperationalGrid contract incomplete: ' . $marker);
    }
}

foreach ([
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    'item.executeUrl',
    'item.approveUrl',
    'name="csrf_token"',
] as $marker) {
    if (!str_contains($cos, $marker)) {
        throw new RuntimeException('COS canonical action surface incomplete: ' . $marker);
    }
}
if (str_contains($cos, '<table') || str_contains($cos, 'tn-')) {
    throw new RuntimeException('COS Control Center must not restore raw/legacy mutation presentation.');
}


foreach ([
    '<twig:CosEntityListItem',
    '<twig:CosActionBar',
    'action="/admin/updateUser/{{ user.id }}"',
    'name="csrf_token"',
    'name="full_name"',
    'name="phone"',
    'name="role"',
    'name="status"',
    'name="password"',
] as $marker) {
    if (!str_contains($users, $marker)) {
        throw new RuntimeException('Users canonical editable EntityList incomplete: ' . $marker);
    }
}
if (str_contains($users, '<table') || str_contains($users, 'tn-')) {
    throw new RuntimeException('Users must not restore raw/legacy editable table presentation.');
}

foreach ([
    '/client-case/quickUpdate/',
    'name="csrf_token"',
    'name="return_url"',
    'value="client-case"',
    'name="stage_id"',
    'name="status"',
    'name="priority"',
    'name="assigned_user_id"',
    'type="submit"',
] as $marker) {
    if (!str_contains($clientCaseItem, $marker)) {
        throw new RuntimeException('Client Case Collection item lost quick-update mutation parity: ' . $marker);
    }
}
if (str_contains($clientCaseItem, '<table') || str_contains($clientCaseItem, 'tn-')) {
    throw new RuntimeException('Client Case Collection must not restore raw/legacy OperationalGrid presentation.');
}

foreach ([
    '.tn-ui-operational-grid__actions',
    '.tn-ui-operational-grid__form',
    '.tn-ui-operational-grid__row-form',
    '.tn-ui-operational-grid__field-stack',
    '.tn-ui-operational-grid__field',
    '.tn-ui-operational-grid__actions-heading',
] as $marker) {
    if (!str_contains($css, $marker)) {
        throw new RuntimeException('OperationalGrid CSS contract incomplete: ' . $marker);
    }
}

echo "OperationalGrid canonical mutation surface passed.\n";
