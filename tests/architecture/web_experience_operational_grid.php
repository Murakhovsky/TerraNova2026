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
$cos = $read('app/Interfaces/Web/View/cos/index.phtml');
$users = $read('app/Interfaces/Web/View/admin/users.phtml');
$clientIndex = $read('app/Interfaces/Web/View/client_case/index.phtml');
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
    "($value['kind'] ?? 'status') === 'stage'",
] as $marker) {
    if (!str_contains($grid, $marker)) {
        throw new RuntimeException('OperationalGrid contract incomplete: ' . $marker);
    }
}

foreach ([
    '$actionRows = [];',
    "'bodyPartial' => 'components/ui/operational_grid'",
    "'kind' => 'form'",
    "'kind' => 'link'",
    "'csrf_token' => $csrfToken",
    'cos/action/',
    '#approval-',
] as $marker) {
    if (!str_contains($cos, $marker)) {
        throw new RuntimeException('COS Proposed Actions migration incomplete: ' . $marker);
    }
}

if (str_contains($cos, '<table class="tn-listing-table">')) {
    throw new RuntimeException('COS Proposed Actions raw table must remain retired.');
}


foreach ([
    '$userRows = [];',
    "'bodyPartial' => 'components/ui/operational_grid'",
    "'_form' => [",
    "'action' => 'admin/updateUser/'",
    "'csrf_token' => (string) (\$csrfToken ?? '')",
    "'kind' => 'field'",
    "'kind' => 'submit'",
    "'name' => 'full_name'",
    "'name' => 'phone'",
    "'name' => 'role'",
    "'name' => 'status'",
    "'name' => 'password'",
] as $marker) {
    if (!str_contains($users, $marker)) {
        throw new RuntimeException('Users OperationalGrid migration incomplete: ' . $marker);
    }
}
if (str_contains($users, '<table class="tn-listing-table tn-users-table">')) {
    throw new RuntimeException('Users raw editable table must remain retired.');
}

foreach ([
    '$caseRows = [];',
    "'bodyPartial' => 'components/ui/operational_grid'",
    "'_form' => [",
    "'action' => 'client-case/quickUpdate/'",
    "'csrf_token' => (string) (\$csrfToken ?? '')",
    "'return_url' => 'client-case'",
    "'kind' => 'stage'",
    "'kind' => 'fields'",
    "'kind' => 'submit'",
    "'name' => 'stage_id'",
    "'name' => 'status'",
    "'name' => 'priority'",
    "'name' => 'assigned_user_id'",
    "'href' => 'client-case/show/'",
] as $marker) {
    if (!str_contains($clientIndex, $marker)) {
        throw new RuntimeException('Client Case OperationalGrid migration incomplete: ' . $marker);
    }
}
if (str_contains($clientIndex, '<table')) {
    throw new RuntimeException('Client Case raw operational table must remain retired.');
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
