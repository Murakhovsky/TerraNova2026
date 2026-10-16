<?php

declare(strict_types=1);

namespace App\Web\Experience\Locale;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class LocaleSubscriber implements EventSubscriberInterface
{
    private const UI_LOCALES = ['uk', 'en'];
    private const PUBLIC_COS_LOCALES = ['uk', 'en', 'de', 'fr', 'pl'];

    public static function getSubscribedEvents(): array
    {
        // RouterListener (32) has populated route attributes; LocaleListener (16)
        // still needs to see our _locale value to update Request and Translator.
        return [KernelEvents::REQUEST => ['onKernelRequest', 17]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $explicitLocale = $request->attributes->get('_locale');
        if (is_string($explicitLocale) && in_array($explicitLocale, self::PUBLIC_COS_LOCALES, true)) {
            return;
        }

        $publicCosLocale = $request->attributes->get('lang');
        if (is_string($publicCosLocale) && in_array($publicCosLocale, self::PUBLIC_COS_LOCALES, true)) {
            $request->attributes->set('_locale', $publicCosLocale);
            return;
        }

        $preferredLocale = null;
        if ($request->hasSession()) {
            $preferredLocale = $request->getSession()->get('cos_locale');
        }

        $locale = is_string($preferredLocale) && in_array($preferredLocale, self::UI_LOCALES, true)
            ? $preferredLocale
            : 'uk';

        $request->attributes->set('_locale', $locale);
    }
}
