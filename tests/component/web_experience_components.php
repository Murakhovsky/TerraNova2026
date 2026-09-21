<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$componentDir = $root . '/symfony/src/Web/Experience/Component';
$templateDir = $root . '/symfony/templates/components/experience';

$classes = glob($componentDir . '/Cos*.php') ?: [];
if (count($classes) < 43) {
    throw new RuntimeException('Expected at least 43 canonical Cos* Twig Components.');
}

foreach ($classes as $classFile) {
    $name = pathinfo($classFile, PATHINFO_FILENAME);
    $source = (string) file_get_contents($classFile);
    if (!preg_match("/template:\\s*'([^']+)'/", $source, $match)) {
        throw new RuntimeException($name . ' does not declare a Twig component template.');
    }
    $template = $root . '/symfony/templates/' . $match[1];
    if (!is_file($template)) {
        throw new RuntimeException($name . ' template is missing: ' . $match[1]);
    }
    foreach (['Domains\\\\', 'Doctrine\\\\', '/api/'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException($name . ' contains forbidden primitive component dependency: ' . $forbidden);
        }
    }
}

foreach (['cos_button.html.twig','cos_input.html.twig','cos_modal.html.twig','cos_data_grid.html.twig','cos_workspace.html.twig','cos_agent_run.html.twig'] as $referenceTemplate) {
    if (!is_file($templateDir . '/' . $referenceTemplate)) {
        throw new RuntimeException('Reference component template is missing: ' . $referenceTemplate);
    }
}

echo sprintf("Wave 12.23 component contract passed: %d canonical components.\n", count($classes));
