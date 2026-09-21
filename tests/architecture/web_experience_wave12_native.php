<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$required = [
    'symfony/src/Application/Experience/Device/DeviceCapability.php',
    'symfony/src/Application/Experience/Device/DeviceCapabilities.php',
    'symfony/src/Application/Experience/Device/ClientVersion.php',
    'symfony/src/Application/Experience/Device/ClientCompatibilityStatus.php',
    'symfony/src/Application/Experience/Device/ClientCompatibility.php',
    'symfony/src/Application/Experience/Device/ClientVersionPolicy.php',
    'symfony/src/Application/Experience/Device/UserDevice.php',
    'symfony/src/Application/Experience/Device/UserDeviceRegistration.php',
    'symfony/src/Application/Experience/Device/Contract/DeviceRegistryInterface.php',
    'symfony/src/Web/Experience/Native/SurfaceContext.php',
    'symfony/src/Web/Experience/Native/Contract/NativeBridgeInterface.php',
    'symfony/src/Web/Experience/Native/Contract/CameraBridgeInterface.php',
    'symfony/src/Web/Experience/Native/Contract/LocationBridgeInterface.php',
    'symfony/src/Web/Experience/Native/Contract/ShareBridgeInterface.php',
    'symfony/src/Web/Experience/Native/Contract/NotificationBridgeInterface.php',
    'symfony/src/Web/Experience/Native/Contract/BiometricBridgeInterface.php',
    'symfony/src/Web/Experience/Native/Contract/FileBridgeInterface.php',
    'symfony/src/Web/Experience/Native/Contract/BarcodeBridgeInterface.php',
    'symfony/src/Web/Experience/Native/Contract/HapticBridgeInterface.php',
    'symfony/src/Web/Experience/Extension/EntityLinkResolver.php',
    'symfony/src/Web/Experience/Native/DeepLink.php',
    'symfony/src/Web/Experience/Native/DeepLinkResolver.php',
    'symfony/src/Command/NativeReadySmokeCommand.php',
    'docs/03-architecture/native-ready-contracts.md',
];

foreach ($required as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Wave 12.18 Native-ready artifact is missing: ' . $relative);
    }
}

$surface = (string) file_get_contents($root . '/symfony/src/Web/Experience/Native/SurfaceContext.php');
foreach ([
    "WebDesktop = 'web_desktop'",
    "WebMobile = 'web_mobile'",
    "Pwa = 'pwa'",
    "NativeIos = 'native_ios'",
    "NativeAndroid = 'native_android'",
] as $marker) {
    if (!str_contains($surface, $marker)) {
        throw new RuntimeException('SurfaceContext value is missing: ' . $marker);
    }
}

$capability = (string) file_get_contents($root . '/symfony/src/Application/Experience/Device/DeviceCapability.php');
foreach (['camera', 'geolocation', 'push', 'biometrics', 'share', 'filesystem', 'contacts', 'haptics', 'barcode'] as $marker) {
    if (!str_contains($capability, "'{$marker}'")) {
        throw new RuntimeException('Device capability is missing: ' . $marker);
    }
}

$applicationDeviceDir = $root . '/symfony/src/Application/Experience/Device';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($applicationDeviceDir));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $contents = (string) file_get_contents($file->getPathname());
    if (str_contains($contents, 'App\\Web\\')) {
        throw new RuntimeException('Application device contracts must not depend on Web UI: ' . $file->getPathname());
    }
}

$bridge = (string) file_get_contents($root . '/symfony/src/Web/Experience/Native/Contract/NativeBridgeInterface.php');
foreach (['surface(): SurfaceContext', 'capabilities(): DeviceCapabilities', 'isAvailable(): bool'] as $marker) {
    if (!str_contains($bridge, $marker)) {
        throw new RuntimeException('NativeBridge contract is incomplete: ' . $marker);
    }
}

$registry = (string) file_get_contents($root . '/symfony/src/Application/Experience/Device/Contract/DeviceRegistryInterface.php');
foreach (['register(', 'find(', 'forUser(', 'touch(', 'revoke('] as $marker) {
    if (!str_contains($registry, $marker)) {
        throw new RuntimeException('DeviceRegistry contract is incomplete: ' . $marker);
    }
}

$status = (string) file_get_contents($root . '/symfony/src/Application/Experience/Device/ClientCompatibilityStatus.php');
foreach (['supported', 'upgrade_recommended', 'upgrade_required'] as $marker) {
    if (!str_contains($status, "'{$marker}'")) {
        throw new RuntimeException('Client compatibility state is missing: ' . $marker);
    }
}

$linkResolver = (string) file_get_contents($root . '/symfony/src/Web/Experience/Extension/EntityLinkResolver.php');
foreach (['entityLinks()', 'Multiple canonical links resolved', "str_starts_with($link->path, '/')"] as $marker) {
    if (!str_contains($linkResolver, $marker)) {
        throw new RuntimeException('EntityLinkResolver contract is incomplete: ' . $marker);
    }
}

$deepLinkResolver = (string) file_get_contents($root . '/symfony/src/Web/Experience/Native/DeepLinkResolver.php');
foreach (['EntityLinkResolver', "'cos://entity/%s/%s'", 'rawurlencode($entity->type)', 'rawurlencode($entity->id)'] as $marker) {
    if (!str_contains($deepLinkResolver, $marker)) {
        throw new RuntimeException('DeepLink resolver contract is incomplete: ' . $marker);
    }
}

$nativeDir = $root . '/symfony/src/Web/Experience/Native';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($nativeDir));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $contents = (string) file_get_contents($file->getPathname());
    foreach (['navigator.userAgent', 'screen.width', 'Capacitor', 'Cordova', 'ReactNative', 'react-native'] as $forbidden) {
        if (str_contains($contents, $forbidden)) {
            throw new RuntimeException('Native-ready contracts contain forbidden platform coupling: ' . $forbidden);
        }
    }
}

$smoke = (string) file_get_contents($root . '/symfony/src/Command/NativeReadySmokeCommand.php');
if (!str_contains($smoke, "name: 'cos:web:native-ready:smoke'")) {
    throw new RuntimeException('Native-ready runtime smoke is missing.');
}

echo "Wave 12.18 Native-ready Contracts passed.\n";
