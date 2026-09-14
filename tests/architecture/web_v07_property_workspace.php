<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$required = [
    'app/Interfaces/Web/Controller/PropertyController.php',
    'app/Interfaces/Web/View/property/manage.phtml',
    'app/Interfaces/Web/View/property/listing.phtml',
    'app/Interfaces/Web/View/property/add.phtml',
    'app/Interfaces/Web/View/property/edit.phtml',
    'app/Interfaces/Web/View/property/group.phtml',
    'app/Interfaces/Web/View/property/submissions.phtml',
    'app/Interfaces/Web/View/property/submission.phtml',
    'frontend/entrypoints/property-workspace.js',
    'frontend/features/property/workspace.css',
    'frontend/features/property/workspace.js',
    'docs/architecture/web-v0.7.md',
];
foreach ($required as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Missing WEB V0.7 Property Workspace artifact: ' . $path);
    }
}

$controller = (string) file_get_contents($root . '/app/Interfaces/Web/Controller/PropertyController.php');
foreach ([
    "\$this->prepareWorkspace('Внутрішній MLS / Listing', 'listing')",
    "\$this->prepareWorkspace('Керування об’єктами', 'objects')",
    "\$this->prepareWorkspace('Додати об’єкт', 'objects', ['terranova-media-manager'])",
    "\$this->prepareWorkspace('Модерація об’єктів', 'submissions')",
    "\$this->prepareWorkspace('Заявка на об’єкт', 'submissions')",
    "\$this->prepareWorkspace('Локація', 'objects', ['terranova-media-manager'])",
    "\$this->prepareWorkspace('Редагувати медіа об’єкта', 'objects', ['terranova-media-manager', 'terranova-copy'])",
    "workspaceSection = 'properties'",
    "array_merge(['property-workspace'], \$assets)",
] as $needle) {
    if (!str_contains($controller, $needle)) {
        throw new RuntimeException('PropertyController is missing WEB V0.7 workspace contract: ' . $needle);
    }
}
if (str_contains($controller, 'Domains\\Frontend')) {
    throw new RuntimeException('WEB V0.7 must not invent a Frontend Domain for a presentation migration.');
}

$actionBody = static function (string $source, string $action): string {
    $needle = 'public function ' . $action . 'Action';
    $start = strpos($source, $needle);
    if ($start === false) {
        throw new RuntimeException('Property action missing from controller: ' . $action);
    }

    $tail = substr($source, $start);
    $boundaries = [];
    foreach (['public function ', 'protected function ', 'private function '] as $boundary) {
        $position = strpos($tail, $boundary, strlen($needle));
        if ($position !== false) {
            $boundaries[] = $position;
        }
    }

    return substr($tail, 0, $boundaries === [] ? null : min($boundaries));
};
foreach (['catalog', 'show', 'presentation', 'submit', 'map', 'compare', 'favour'] as $publicAction) {
    if (str_contains($actionBody($controller, $publicAction), 'prepareWorkspace(')) {
        throw new RuntimeException('Public/portal Property action was incorrectly pulled into Company Workspace: ' . $publicAction);
    }
}

$layout = (string) file_get_contents($root . '/app/Interfaces/Web/View/index.phtml');
if (!str_contains($layout, "'layoutOwned' => true")) {
    throw new RuntimeException('Global Web layout must continue to own migrated Workspace shells.');
}

foreach (['manage.phtml', 'listing.phtml', 'add.phtml', 'edit.phtml', 'group.phtml', 'submissions.phtml', 'submission.phtml'] as $viewFile) {
    $view = (string) file_get_contents($root . '/app/Interfaces/Web/View/property/' . $viewFile);
    if (!str_contains($view, "partial('shared/manager_header'")) {
        throw new RuntimeException('Legacy Property view changed unexpectedly; the layout-owned compatibility guard must cover its historical shell call: ' . $viewFile);
    }
    if (str_contains($view, '/assets/js/') || str_contains($view, '/assets/css/')) {
        throw new RuntimeException('Property Workspace view bypasses Vite: ' . $viewFile);
    }
}

$entrypoint = (string) file_get_contents($root . '/frontend/entrypoints/property-workspace.js');
foreach (["../features/property/workspace.css", "../features/property/workspace.js"] as $needle) {
    if (!str_contains($entrypoint, $needle)) {
        throw new RuntimeException('Property Workspace Vite entrypoint is incomplete: ' . $needle);
    }
}

$workspaceJs = (string) file_get_contents($root . '/frontend/features/property/workspace.js');
foreach (["dataset.propertyWorkspace = 'true'", "addEventListener('submit'", 'aria-busy'] as $needle) {
    if (!str_contains($workspaceJs, $needle)) {
        throw new RuntimeException('Property Workspace progressive enhancement is incomplete: ' . $needle);
    }
}

$workspaceCss = (string) file_get_contents($root . '/frontend/features/property/workspace.css');
foreach (['.tn-property-workspace .tn-page', '.tn-listing-table-wrap', '@media (max-width: 650px)'] as $needle) {
    if (!str_contains($workspaceCss, $needle)) {
        throw new RuntimeException('Property Workspace responsive styling is incomplete: ' . $needle);
    }
}

$vite = (string) file_get_contents($root . '/vite.config.js');
if (!str_contains($vite, "'property-workspace': resolve(import.meta.dirname, 'frontend/entrypoints/property-workspace.js')")) {
    throw new RuntimeException('Vite does not expose the WEB V0.7 Property Workspace entrypoint.');
}

$assetTest = (string) file_get_contents($root . '/tests/architecture/frontend_assets.php');
if (!str_contains($assetTest, "'property-workspace'")) {
    throw new RuntimeException('Frontend asset architecture does not validate the Property Workspace bundle.');
}

$propertyNavigation = (string) file_get_contents($root . '/app/Interfaces/Web/Navigation/PropertyNavigationContributor.php');
foreach (["'key' => 'properties'", "'key' => 'objects'", "'key' => 'listing'", "'key' => 'submissions'"] as $needle) {
    if (!str_contains($propertyNavigation, $needle)) {
        throw new RuntimeException('Property module must continue to own Property Workspace navigation: ' . $needle);
    }
}

echo "WEB V0.7 Property Workspace architecture passed: Inventory, Listing, editor, locations and moderation use the shared layout-owned shell while public Property surfaces remain separate.\n";
