<?php

declare(strict_types=1);

namespace App\Web\Experience\Native;

enum SurfaceContext: string
{
    case WebDesktop = 'web_desktop';
    case WebMobile = 'web_mobile';
    case Pwa = 'pwa';
    case NativeIos = 'native_ios';
    case NativeAndroid = 'native_android';

    public function isNative(): bool
    {
        return $this === self::NativeIos || $this === self::NativeAndroid;
    }

    public function isWeb(): bool
    {
        return !$this->isNative();
    }
}
