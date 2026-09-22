<?php

declare(strict_types=1);

/**
 * Web Platform v1 public contracts.
 *
 * Implementation classes, controllers, templates and domain providers are intentionally
 * not frozen. A path in this list may change only together with an ADR change.
 *
 * @return list<string>
 */
return [
    'app/Kernel/Module/ModuleExtensionPoint.php',

    'symfony/src/Web/Experience/Model/EntityRef.php',

    'symfony/src/Web/Experience/Action/UIAction.php',
    'symfony/src/Web/Experience/Action/UIActionIntent.php',
    'symfony/src/Web/Experience/Action/UIActionPlacement.php',
    'symfony/src/Web/Experience/Action/UIActionDangerLevel.php',
    'symfony/src/Web/Experience/Action/UIActionConfirmation.php',

    'symfony/src/Web/Experience/Extension/Contract/WebExtensionProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/NavigationProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/SearchProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/CommandProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/WorkspaceProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/WorkspaceExtensionProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/ActionProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/ActivityProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/EntityLinkProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/NotificationProviderInterface.php',
    'symfony/src/Web/Experience/Extension/Contract/DashboardWidgetProviderInterface.php',

    'symfony/src/Web/Experience/Extension/Model/WebExtensionContext.php',
    'symfony/src/Web/Experience/Extension/Model/WorkspaceDefinition.php',
    'symfony/src/Web/Experience/Extension/Model/WorkspaceExtension.php',

    'symfony/src/Web/Experience/Workspace/WorkspaceSlot.php',
    'symfony/src/Web/Experience/Workspace/WorkspaceViewModel.php',

    'symfony/src/Web/Experience/Shell/ShellViewModel.php',
    'symfony/src/Web/Experience/Shell/ShellNavigationItem.php',
    'symfony/src/Web/Experience/Shell/ShellCommandItem.php',
    'symfony/src/Web/Experience/Shell/ShellBreadcrumb.php',
    'symfony/src/Web/Experience/Shell/ShellConnectionState.php',

    'symfony/src/Web/Experience/Data/DataGridColumn.php',
    'symfony/src/Web/Experience/Data/DataGridFilter.php',
    'symfony/src/Web/Experience/Data/DataGridQuery.php',
    'symfony/src/Web/Experience/Data/DataGridPage.php',
    'symfony/src/Web/Experience/Data/DataGridSavedView.php',
    'symfony/src/Web/Experience/Data/DataGridState.php',

    'symfony/src/Web/Experience/Form/FormInputDto.php',
    'symfony/src/Web/Experience/Form/FormDraftPolicy.php',
    'symfony/src/Web/Experience/Form/FormErrorSummary.php',

    'symfony/src/Web/Experience/Realtime/RealtimeTopic.php',

    'symfony/src/Application/Experience/Device/DeviceCapability.php',
    'symfony/src/Application/Experience/Device/DeviceCapabilities.php',
    'symfony/src/Application/Experience/Device/ClientVersion.php',
    'symfony/src/Application/Experience/Device/ClientCompatibilityStatus.php',
    'symfony/src/Application/Experience/Device/ClientCompatibility.php',
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
    'symfony/src/Web/Experience/Native/DeepLink.php',
];
