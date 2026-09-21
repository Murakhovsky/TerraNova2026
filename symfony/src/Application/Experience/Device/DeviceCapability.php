<?php

declare(strict_types=1);

namespace App\Application\Experience\Device;

enum DeviceCapability: string
{
    case Camera = 'camera';
    case Geolocation = 'geolocation';
    case Push = 'push';
    case Biometrics = 'biometrics';
    case Share = 'share';
    case Filesystem = 'filesystem';
    case Contacts = 'contacts';
    case Haptics = 'haptics';
    case Barcode = 'barcode';
}
