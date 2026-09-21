<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$components = [
    'CosModal' => 'cos_modal.html.twig',
    'CosDrawer' => 'cos_drawer.html.twig',
    'CosDropdown' => 'cos_dropdown.html.twig',
    'CosTabs' => 'cos_tabs.html.twig',
    'CosPopover' => 'cos_popover.html.twig',
    'CosTooltip' => 'cos_tooltip.html.twig',
    'CosConfirm' => 'cos_confirm.html.twig',
    'CosToast' => 'cos_toast.html.twig',
];

foreach ($components as $component => $template) {
    $classPath = $root . '/symfony/src/Web/Experience/Component/' . $component . '.php';
    $templatePath = $root . '/symfony/templates/components/experience/' . $template;

    if (!is_file($classPath) || !is_file($templatePath)) {
        throw new RuntimeException('Wave 12.7 interaction component is missing: ' . $component);
    }

    $class = (string) file_get_contents($classPath);
    foreach (['Domains\\', 'Doctrine\\', 'PDO', 'HttpClientInterface', '/api/'] as $forbidden) {
        if (str_contains($class, $forbidden)) {
            throw new RuntimeException(sprintf(
                'Interaction component %s crossed a presentation boundary: %s',
                $component,
                $forbidden,
            ));
        }
    }
}

$templateContracts = [
    'cos_modal.html.twig' => ['<dialog', 'aria-labelledby', 'data-controller="dialog"', '{% block content %}'],
    'cos_drawer.html.twig' => ['<dialog', 'cos-drawer', 'data-controller="dialog"', '{% block content %}'],
    'cos_dropdown.html.twig' => ['aria-haspopup="menu"', 'role="menu"', 'aria-labelledby', 'data-controller="dropdown"', '{% block content %}'],
    'cos_tabs.html.twig' => ['role="tablist"', 'role="tab"', 'aria-selected', 'data-controller="tabs"', '{% block content %}'],
    'cos_popover.html.twig' => ['role="dialog"', 'aria-expanded', 'tabindex="-1"', 'data-controller="popover"', '{% block content %}'],
    'cos_tooltip.html.twig' => ['role="tooltip"', 'aria-describedby', 'data-controller="tooltip"'],
    'cos_confirm.html.twig' => ['<dialog', 'aria-describedby', 'data-controller="dialog confirm"', 'data-confirm-step-up-value', '{% block content %}'],
    'cos_toast.html.twig' => ['aria-live', 'data-controller="toast"', 'data-toast-duration-value'],
];

foreach ($templateContracts as $template => $contracts) {
    $source = (string) file_get_contents(
        $root . '/symfony/templates/components/experience/' . $template,
    );

    foreach ($contracts as $contract) {
        if (!str_contains($source, $contract)) {
            throw new RuntimeException(sprintf(
                'Interaction template %s is missing: %s',
                $template,
                $contract,
            ));
        }
    }

    foreach (['fetch(', 'axios', '/api/v1/', 'Domains\\'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf(
                'Interaction template %s contains forbidden transport/business dependency: %s',
                $template,
                $forbidden,
            ));
        }
    }
}

$controllers = [
    'dialog_trigger_controller.js',
    'dialog_controller.js',
    'dropdown_controller.js',
    'tabs_controller.js',
    'confirm_controller.js',
    'toast_controller.js',
    'popover_controller.js',
    'tooltip_controller.js',
];

foreach ($controllers as $controller) {
    $path = $root . '/symfony/assets/controllers/' . $controller;
    if (!is_file($path)) {
        throw new RuntimeException('Interaction Stimulus controller is missing: ' . $controller);
    }

    $source = (string) file_get_contents($path);
    foreach (['fetch(', 'axios', '/api/', 'localStorage', 'sessionStorage', 'Domains\\', 'Application\\'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf(
                'Interaction controller %s contains forbidden state/data access: %s',
                $controller,
                $forbidden,
            ));
        }
    }
}

$dialog = (string) file_get_contents($root . '/symfony/assets/controllers/dialog_controller.js');
foreach (['HTMLDialogElement', 'dismissibleValue', 'event.preventDefault()', '__cosReturnFocus'] as $contract) {
    if (!str_contains($dialog, $contract)) {
        throw new RuntimeException('Dialog accessibility behavior is missing: ' . $contract);
    }
}

$dropdown = (string) file_get_contents($root . '/symfony/assets/controllers/dropdown_controller.js');
foreach (["event.key === 'ArrowDown'", "event.key === 'ArrowUp'", "event.key === 'Home'", "event.key === 'End'", 'triggerTarget.focus()'] as $contract) {
    if (!str_contains($dropdown, $contract)) {
        throw new RuntimeException('Dropdown keyboard behavior is missing: ' . $contract);
    }
}

$tabs = (string) file_get_contents($root . '/symfony/assets/controllers/tabs_controller.js');
foreach (["event.key === 'ArrowRight'", "event.key === 'ArrowLeft'", "event.key === 'Home'", "event.key === 'End'", 'aria-selected'] as $contract) {
    if (!str_contains($tabs, $contract)) {
        throw new RuntimeException('Tabs keyboard behavior is missing: ' . $contract);
    }
}

$popover = (string) file_get_contents($root . '/symfony/assets/controllers/popover_controller.js');
foreach (['panelTarget.focus', 'triggerTarget.focus()'] as $contract) {
    if (!str_contains($popover, $contract)) {
        throw new RuntimeException('Popover focus behavior is missing: ' . $contract);
    }
}

$confirm = (string) file_get_contents($root . '/symfony/assets/controllers/confirm_controller.js');
foreach (["=== 'CONFIRM'", "new CustomEvent('cos:confirm'", 'stepUpValue'] as $contract) {
    if (!str_contains($confirm, $contract)) {
        throw new RuntimeException('Confirm behavior is missing: ' . $contract);
    }
}

$styles = (string) file_get_contents($root . '/symfony/assets/styles/interactions.css');
foreach ([
    '.cos-dialog::backdrop',
    '.cos-drawer--end',
    '.cos-dropdown__menu',
    '.cos-tabs__tab',
    '.cos-popover-host',
    '.cos-tooltip-host',
    '.cos-confirm--danger',
    '.cos-toast',
    '@media (max-width: 760px)',
    '@media (prefers-reduced-motion: reduce)',
] as $selector) {
    if (!str_contains($styles, $selector)) {
        throw new RuntimeException('Interaction style contract is missing: ' . $selector);
    }
}
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $styles) === 1 || str_contains($styles, '--tn-')) {
    throw new RuntimeException('Interaction styles must use canonical COS semantic tokens.');
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
if (!str_contains($appCss, "@import './interactions.css';")) {
    throw new RuntimeException('Interaction styles are not loaded by canonical AssetMapper CSS.');
}

$catalog = (string) file_get_contents(
    $root . '/symfony/templates/experience/design_system_catalog.html.twig',
);
foreach ([
    'Interaction components',
    '<twig:CosModal',
    '<twig:CosDrawer',
    '<twig:CosDropdown',
    '<twig:CosTabs',
    '<twig:CosPopover',
    '<twig:CosTooltip',
    '<twig:CosConfirm',
    '<twig:CosToast',
] as $marker) {
    if (!str_contains($catalog, $marker)) {
        throw new RuntimeException('Interaction component catalog marker is missing: ' . $marker);
    }
}

echo "Wave 12.7 Interaction Components passed.\n";
