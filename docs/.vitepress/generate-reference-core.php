<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$checkOnly = in_array('--check', $argv, true);

require_once $repoRoot . '/app/Kernel/Module/KernelVersion.php';
require_once $repoRoot . '/app/Kernel/Module/ModuleExtensionRegistry.php';

$modulePaths = glob($repoRoot . '/app/Domains/*/module.php') ?: [];
sort($modulePaths, SORT_STRING);

$modules = [];
foreach ($modulePaths as $modulePath) {
    $definition = require $modulePath;
    if (!is_array($definition)) {
        fwrite(STDERR, sprintf("Invalid module definition: %s\n", relativePath($repoRoot, $modulePath)));
        exit(1);
    }

    foreach (['id', 'name', 'version'] as $requiredKey) {
        if (!isset($definition[$requiredKey]) || !is_string($definition[$requiredKey]) || $definition[$requiredKey] === '') {
            fwrite(STDERR, sprintf("Module %s is missing '%s'.\n", relativePath($repoRoot, $modulePath), $requiredKey));
            exit(1);
        }
    }

    $definition['_source'] = relativePath($repoRoot, $modulePath);
    $modules[] = $definition;
}

usort($modules, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

$extensionPoints = [
    \Kernel\Module\ModuleExtensionRegistry::API_ROUTES => ['kind' => 'built-in', 'contributions' => []],
    \Kernel\Module\ModuleExtensionRegistry::TENANT_CONFIGURATION => ['kind' => 'built-in', 'contributions' => []],
];

foreach ($modules as $module) {
    $contributions = is_array($module['contributions'] ?? null) ? $module['contributions'] : [];

    foreach (stringList($contributions['api_route_contributor_services'] ?? []) as $serviceId) {
        addExtension($extensionPoints, (string) $module['id'], \Kernel\Module\ModuleExtensionRegistry::API_ROUTES, $serviceId, 'built-in');
    }
    foreach (stringList($contributions['configuration_provisioner_services'] ?? []) as $serviceId) {
        addExtension($extensionPoints, (string) $module['id'], \Kernel\Module\ModuleExtensionRegistry::TENANT_CONFIGURATION, $serviceId, 'built-in');
    }

    $customExtensions = is_array($contributions['extension_services'] ?? null)
        ? $contributions['extension_services']
        : [];
    foreach ($customExtensions as $extensionPoint => $serviceIds) {
        if (!is_string($extensionPoint)) {
            continue;
        }
        foreach (stringList($serviceIds) as $serviceId) {
            addExtension($extensionPoints, (string) $module['id'], $extensionPoint, $serviceId, 'module-defined');
        }
    }
}

ksort($extensionPoints, SORT_STRING);
foreach ($extensionPoints as &$extensionPoint) {
    usort(
        $extensionPoint['contributions'],
        static fn (array $left, array $right): int => [$left['module'], $left['service']] <=> [$right['module'], $right['service']],
    );
}
unset($extensionPoint);

$outputs = [
    $repoRoot . '/docs/12-reference/module-capabilities.md' => renderModules($modules, \Kernel\Module\KernelVersion::VERSION),
    $repoRoot . '/docs/12-reference/extension-points.md' => renderExtensions($extensionPoints),
];

$failed = false;
foreach ($outputs as $path => $content) {
    if ($checkOnly) {
        $existing = is_file($path) ? file_get_contents($path) : false;
        if ($existing === false || normalizeNewlines($existing) !== normalizeNewlines($content)) {
            fwrite(STDERR, sprintf(
                "Generated reference is stale: %s. Run npm run docs:generate.\n",
                relativePath($repoRoot, $path),
            ));
            $failed = true;
        }
        continue;
    }

    if (file_put_contents($path, $content) === false) {
        fwrite(STDERR, sprintf("Cannot write generated reference: %s\n", relativePath($repoRoot, $path)));
        $failed = true;
        continue;
    }
    fwrite(STDOUT, sprintf("Generated %s\n", relativePath($repoRoot, $path)));
}

exit($failed ? 1 : 0);

/** @return list<string> */
function stringList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter($value, 'is_string'));
}

/** @param array<string, array{kind: string, contributions: list<array{module: string, service: string}>}> $points */
function addExtension(array &$points, string $moduleId, string $point, string $serviceId, string $kind): void
{
    $points[$point] ??= ['kind' => $kind, 'contributions' => []];
    $points[$point]['contributions'][] = ['module' => $moduleId, 'service' => $serviceId];
}

/** @param list<array<string, mixed>> $modules */
function renderModules(array $modules, string $kernelVersion): string
{
    $lines = [
        '---',
        'title: Module and Capability Reference',
        'description: Generated reference з module manifests та declared capabilities.',
        'status: generated',
        'kind: reference',
        'generated: true',
        '---',
        '',
        '<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->',
        '',
        '# Module and Capability Reference',
        '',
        '> Джерело істини: `app/Domains/*/module.php` та `app/Kernel/Module/KernelVersion.php`.',
        '',
        '## Kernel contract version',
        '',
        sprintf('`Kernel\\Module\\KernelVersion::VERSION = %s`.', $kernelVersion),
        '',
        '## Registered modules',
        '',
        '| ID | Name | Version | Schema | Kernel constraint | Default | Dependencies | Source |',
        '| --- | --- | --- | --- | --- | --- | --- | --- |',
    ];

    foreach ($modules as $module) {
        $lines[] = sprintf(
            '| `%s` | %s | `%s` | `%s` | `%s` | %s | %s | `%s` |',
            table((string) $module['id']),
            table((string) $module['name']),
            table((string) $module['version']),
            table((string) ($module['schema_version'] ?? '1.0.0')),
            table((string) ($module['kernel_constraint'] ?? '*')),
            !empty($module['enabled_by_default']) ? 'yes' : 'no',
            table(dependencySummary($module)),
            table((string) $module['_source']),
        );
    }

    foreach ($modules as $module) {
        $contributions = is_array($module['contributions'] ?? null) ? $module['contributions'] : [];
        $runtimeService = $contributions['runtime_module_service'] ?? null;
        $runtimeService = is_string($runtimeService) && $runtimeService !== '' ? code($runtimeService) : '—';

        $lines[] = '';
        $lines[] = sprintf('## %s (`%s`)', (string) $module['name'], (string) $module['id']);
        $lines[] = '';
        if (isset($module['description']) && is_string($module['description']) && $module['description'] !== '') {
            $lines[] = $module['description'];
            $lines[] = '';
        }
        $lines[] = sprintf('- runtime module service: %s;', $runtimeService);
        $lines[] = sprintf('- job handlers: %s;', inlineList(stringList($contributions['job_handler_services'] ?? [])));
        $lines[] = sprintf('- API route contributors: %s;', inlineList(stringList($contributions['api_route_contributor_services'] ?? [])));
        $lines[] = sprintf('- configuration provisioners: %s;', inlineList(stringList($contributions['configuration_provisioner_services'] ?? [])));
        $lines[] = sprintf('- migrations: %s.', inlineList(stringList($contributions['migration_files'] ?? [])));
        $lines[] = '';
        $lines[] = '### Declared capabilities';
        $lines[] = '';

        $capabilities = stringList($contributions['capabilities'] ?? []);
        if ($capabilities === []) {
            $lines[] = 'Manifest capabilities не задекларовані.';
        } else {
            sort($capabilities, SORT_STRING);
            foreach ($capabilities as $capability) {
                $lines[] = sprintf('- `%s`;', $capability);
            }
        }
    }

    $lines[] = '';
    $lines[] = '## Scope';
    $lines[] = '';
    $lines[] = 'Ця сторінка описує тільки факти з installable module manifests. Domain directories без `module.php` сюди не потрапляють. Capability enum або runtime authority можуть мати ширший vocabulary і повинні документуватися окремим generated reference.';
    $lines[] = '';

    return implode("\n", $lines);
}

/** @param array<string, array{kind: string, contributions: list<array{module: string, service: string}>}> $points */
function renderExtensions(array $points): string
{
    $lines = [
        '---',
        'title: Module Extension Points',
        'description: Generated registry of Kernel and module-defined extension points.',
        'status: generated',
        'kind: reference',
        'generated: true',
        '---',
        '',
        '<!-- GENERATED FILE: DO NOT EDIT MANUALLY. Run `npm run docs:generate`. -->',
        '',
        '# Module Extension Points',
        '',
        '> Джерело істини: `ModuleExtensionRegistry` та `extension_services` у `app/Domains/*/module.php`.',
        '',
        '| Extension point | Kind | Contributions |',
        '| --- | --- | ---: |',
    ];

    foreach ($points as $point => $definition) {
        $lines[] = sprintf('| `%s` | %s | %d |', table($point), table($definition['kind']), count($definition['contributions']));
    }

    foreach ($points as $point => $definition) {
        $lines[] = '';
        $lines[] = sprintf('## `%s`', $point);
        $lines[] = '';
        $lines[] = sprintf('Kind: **%s**.', $definition['kind']);
        $lines[] = '';

        if ($definition['contributions'] === []) {
            $lines[] = 'Поточних module contributions немає.';
            continue;
        }

        $lines[] = '| Module | Service |';
        $lines[] = '| --- | --- |';
        foreach ($definition['contributions'] as $contribution) {
            $lines[] = sprintf('| `%s` | `%s` |', table($contribution['module']), table($contribution['service']));
        }
    }

    $lines[] = '';
    $lines[] = '## Registration semantics';
    $lines[] = '';
    $lines[] = 'Built-in points `api.routes` і `tenant.configuration` створюються Kernel registry з typed contribution lists. Інші точки реєструються через manifest `extension_services`. Runtime registry зберігає трійку `module_id + extension_point + service_id`.';
    $lines[] = '';

    return implode("\n", $lines);
}

/** @param array<string, mixed> $module */
function dependencySummary(array $module): string
{
    $dependencies = $module['dependencies'] ?? [];
    $requires = is_array($module['requires'] ?? null) ? $module['requires'] : [];
    if (!is_array($dependencies)) {
        return '—';
    }

    $values = [];
    foreach ($dependencies as $key => $value) {
        if (is_int($key) && is_string($value)) {
            $constraint = isset($requires[$value]) && is_string($requires[$value]) ? $requires[$value] : '*';
            $values[] = $constraint === '*' ? $value : sprintf('%s %s', $value, $constraint);
        } elseif (is_string($key) && is_string($value)) {
            $values[] = sprintf('%s %s', $key, $value);
        }
    }

    foreach ($requires as $dependency => $constraint) {
        if (!is_string($dependency) || !is_string($constraint)) {
            continue;
        }
        $alreadyIncluded = array_filter($values, static fn (string $value): bool => str_starts_with($value, $dependency . ' ') || $value === $dependency);
        if ($alreadyIncluded === []) {
            $values[] = sprintf('%s %s', $dependency, $constraint);
        }
    }

    sort($values, SORT_STRING);
    return $values === [] ? '—' : implode(', ', $values);
}

/** @param list<string> $values */
function inlineList(array $values): string
{
    if ($values === []) {
        return '—';
    }
    sort($values, SORT_STRING);
    return implode(', ', array_map('code', $values));
}

function code(string $value): string
{
    return sprintf('`%s`', str_replace('`', '\\`', $value));
}

function table(string $value): string
{
    return str_replace('|', '\\|', $value);
}

function normalizeNewlines(string $value): string
{
    return str_replace("\r\n", "\n", $value);
}

function relativePath(string $root, string $path): string
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    return ltrim(str_starts_with($path, $root) ? substr($path, strlen($root)) : $path, '/');
}
