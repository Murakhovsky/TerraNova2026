<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Web/Experience/Form/FormInputDto.php',
    'symfony/src/Web/Experience/Form/FormDraftPolicy.php',
    'symfony/src/Web/Experience/Form/FormErrorSummary.php',
    'symfony/src/Web/Experience/Form/Reference/FormsCatalogInput.php',
    'symfony/src/Web/Experience/Form/Reference/FormsCatalogType.php',
    'symfony/src/Web/Experience/Component/CosValidationSummary.php',
    'symfony/templates/components/experience/cos_validation_summary.html.twig',
    'symfony/assets/controllers/form_state_controller.js',
    'symfony/assets/controllers/form_validation_controller.js',
    'symfony/assets/controllers/conditional_fields_controller.js',
    'symfony/assets/styles/forms.css',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.8 Forms Platform file is missing: ' . $relative);
    }
}

$input = (string) file_get_contents($root . '/symfony/src/Web/Experience/Form/Reference/FormsCatalogInput.php');
foreach (['implements FormInputDto', 'Assert\\NotBlank', 'Assert\\Email'] as $marker) {
    if (!str_contains($input, $marker)) {
        throw new RuntimeException('Reference form DTO contract is missing: ' . $marker);
    }
}
foreach (['Doctrine\\', 'Repository', 'EntityManager', 'Domains\\'] as $forbidden) {
    if (str_contains($input, $forbidden)) {
        throw new RuntimeException('Reference form DTO crossed adapter boundary: ' . $forbidden);
    }
}

$type = (string) file_get_contents($root . '/symfony/src/Web/Experience/Form/Reference/FormsCatalogType.php');
foreach ([
    "'data_class' => FormsCatalogInput::class",
    "'autocomplete' => true",
    'DropzoneType::class',
    "'mapped' => false",
    'data-conditional-fields-target',
] as $marker) {
    if (!str_contains($type, $marker)) {
        throw new RuntimeException('Reference Symfony Form contract is missing: ' . $marker);
    }
}
foreach (['EntityType::class', 'Doctrine\\', 'Repository', 'EntityManager', '/api/'] as $forbidden) {
    if (str_contains($type, $forbidden)) {
        throw new RuntimeException('Reference Symfony Form crossed platform boundary: ' . $forbidden);
    }
}

foreach (['CosInput', 'CosSelect', 'CosTextarea'] as $component) {
    $class = (string) file_get_contents($root . '/symfony/src/Web/Experience/Component/' . $component . '.php');

    foreach (['public array $errors', 'public ?string $help', 'describedBy'] as $marker) {
        if (!str_contains($class, $marker)) {
            throw new RuntimeException(sprintf('Field component %s lost form semantics: %s', $component, $marker));
        }
    }

    foreach (['Domains\\', 'Doctrine\\', 'Repository', '/api/'] as $forbidden) {
        if (str_contains($class, $forbidden)) {
            throw new RuntimeException(sprintf('Field component %s crossed presentation boundary: %s', $component, $forbidden));
        }
    }
}

foreach (['cos_input.html.twig', 'cos_select.html.twig', 'cos_textarea.html.twig'] as $template) {
    $source = (string) file_get_contents($root . '/symfony/templates/components/experience/' . $template);

    foreach (['aria-invalid', 'aria-describedby', 'cos-field__errors', 'cos-field__help'] as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException(sprintf('Field template %s lost accessibility semantics: %s', $template, $marker));
        }
    }
}

$validation = (string) file_get_contents($root . '/symfony/templates/components/experience/cos_validation_summary.html.twig');
foreach (['role="alert"', 'aria-labelledby', 'tabindex="-1"'] as $marker) {
    if (!str_contains($validation, $marker)) {
        throw new RuntimeException('Validation summary accessibility contract is missing: ' . $marker);
    }
}

foreach (['form_state_controller.js', 'form_validation_controller.js', 'conditional_fields_controller.js'] as $controller) {
    $source = (string) file_get_contents($root . '/symfony/assets/controllers/' . $controller);

    foreach (['fetch(', 'axios', '/api/', 'localStorage', 'sessionStorage', 'Domains\\', 'Application\\'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException(sprintf('Forms controller %s contains forbidden data/business access: %s', $controller, $forbidden));
        }
    }
}

$formState = (string) file_get_contents($root . '/symfony/assets/controllers/form_state_controller.js');
foreach (['beforeunload', 'turbo:before-visit', 'cos:form-dirty', 'cos:draft-save-requested', "draftModeValue === 'autosave'"] as $marker) {
    if (!str_contains($formState, $marker)) {
        throw new RuntimeException('Form dirty/draft behavior is missing: ' . $marker);
    }
}

$styles = (string) file_get_contents($root . '/symfony/assets/styles/forms.css');
foreach ([
    '.cos-form__actions',
    'position: sticky',
    'env(safe-area-inset-bottom)',
    '@media (max-width: 760px)',
    '.cos-validation-summary',
    '.dropzone-container',
    '.ts-control',
] as $marker) {
    if (!str_contains($styles, $marker)) {
        throw new RuntimeException('Forms style contract is missing: ' . $marker);
    }
}
if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $styles) === 1 || str_contains($styles, '--tn-')) {
    throw new RuntimeException('Forms styles must use canonical COS semantic tokens.');
}

$appCss = (string) file_get_contents($root . '/symfony/assets/styles/app.css');
if (!str_contains($appCss, "@import './forms.css';")) {
    throw new RuntimeException('Forms styles are not loaded by canonical AssetMapper CSS.');
}

$controllers = (string) file_get_contents($root . '/symfony/assets/controllers.json');
if (!str_contains($controllers, '"@symfony/ux-dropzone/dist/style.min.css": false')) {
    throw new RuntimeException('UX Dropzone default visual skin must be disabled in favor of COS tokens.');
}

$catalog = (string) file_get_contents($root . '/symfony/templates/experience/design_system_catalog.html.twig');
foreach ([
    'Form primitives / Forms Platform',
    'form_start(formsDemo',
    'form-state form-validation conditional-fields',
    '<twig:CosValidationSummary',
    'catalog-form-modal',
] as $marker) {
    if (!str_contains($catalog, $marker)) {
        throw new RuntimeException('Forms Platform catalog marker is missing: ' . $marker);
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/FormsPlatformSmokeCommand.php');
foreach (['cos:web:forms:smoke', 'FormFactoryInterface', 'DropzoneType', "getOption('autocomplete')"] as $marker) {
    if (!str_contains($smoke, $marker)) {
        throw new RuntimeException('Forms Platform runtime smoke contract is missing: ' . $marker);
    }
}

echo "Wave 12.8 Forms Platform passed.\n";
