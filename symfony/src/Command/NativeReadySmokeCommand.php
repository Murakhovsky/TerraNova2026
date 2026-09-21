<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Experience\Device\ClientCompatibilityStatus;
use App\Application\Experience\Device\ClientVersion;
use App\Application\Experience\Device\ClientVersionPolicy;
use App\Application\Experience\Device\DeviceCapabilities;
use App\Application\Experience\Device\DeviceCapability;
use App\Application\Experience\Device\UserDeviceRegistration;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Native\DeepLink;
use App\Web\Experience\Native\SurfaceContext;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:web:native-ready:smoke',
    description: 'Validate canonical COS native-ready contracts without a production native shell.',
)]
final class NativeReadySmokeCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $capabilities = new DeviceCapabilities([
            DeviceCapability::Camera,
            DeviceCapability::Geolocation,
            DeviceCapability::Barcode,
        ]);

        if (!$capabilities->supports(DeviceCapability::Camera) || $capabilities->supports(DeviceCapability::Biometrics)) {
            $output->writeln('<error>Device capability projection is invalid.</error>');

            return Command::FAILURE;
        }

        $policy = new ClientVersionPolicy(
            minimumSupportedVersion: new ClientVersion('1.4.0'),
            recommendedVersion: new ClientVersion('1.6.0'),
            requiredCapabilities: new DeviceCapabilities([
                DeviceCapability::Camera,
                DeviceCapability::Geolocation,
            ]),
        );

        $required = $policy->evaluate(new ClientVersion('1.3.9'), $capabilities);
        $recommended = $policy->evaluate(new ClientVersion('1.5.0'), $capabilities);
        $supported = $policy->evaluate(new ClientVersion('1.6.0'), $capabilities);

        if (
            $required->status !== ClientCompatibilityStatus::UpgradeRequired
            || $recommended->status !== ClientCompatibilityStatus::UpgradeRecommended
            || $supported->status !== ClientCompatibilityStatus::Supported
        ) {
            $output->writeln('<error>Client version compatibility contract is invalid.</error>');

            return Command::FAILURE;
        }

        $missingCapability = $policy->evaluate(
            new ClientVersion('1.6.0'),
            new DeviceCapabilities([DeviceCapability::Camera]),
        );

        if (
            $missingCapability->status !== ClientCompatibilityStatus::UpgradeRequired
            || count($missingCapability->missingCapabilities) !== 1
            || $missingCapability->missingCapabilities[0] !== DeviceCapability::Geolocation
        ) {
            $output->writeln('<error>Required device capability compatibility is invalid.</error>');

            return Command::FAILURE;
        }

        $device = (new UserDeviceRegistration(
            id: 'device-native-1',
            userId: '1',
            platform: 'ios',
            appVersion: new ClientVersion('1.6.0'),
            deviceName: 'COS test device',
            capabilities: $capabilities,
            registeredAt: new DateTimeImmutable('2026-09-21T12:00:00+00:00'),
            pushToken: 'test-token',
        ))->toDevice();

        if ($device->isRevoked() || $device->platform !== 'ios') {
            $output->writeln('<error>UserDevice registration contract is invalid.</error>');

            return Command::FAILURE;
        }

        $surfaceValues = array_map(
            static fn (SurfaceContext $surface): string => $surface->value,
            SurfaceContext::cases(),
        );

        if ($surfaceValues !== ['web_desktop', 'web_mobile', 'pwa', 'native_ios', 'native_android']) {
            $output->writeln('<error>SurfaceContext contract is invalid.</error>');

            return Command::FAILURE;
        }

        if (!SurfaceContext::NativeIos->isNative() || SurfaceContext::Pwa->isNative()) {
            $output->writeln('<error>SurfaceContext native classification is invalid.</error>');

            return Command::FAILURE;
        }

        $entity = new EntityRef('sales.deal', '42');
        $deepLink = new DeepLink(
            entity: $entity,
            webPath: '/sales/deals/42',
            nativeUri: 'cos://entity/sales.deal/42',
        );

        if ($deepLink->entity->key() !== 'sales.deal:42' || $deepLink->nativeUri !== 'cos://entity/sales.deal/42') {
            $output->writeln('<error>DeepLink contract is invalid.</error>');

            return Command::FAILURE;
        }

        $output->writeln('COS Native-ready contracts runtime passed.');

        return Command::SUCCESS;
    }
}
