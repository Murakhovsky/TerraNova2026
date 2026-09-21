---
title: Контракти для нативної оболонки
description: Канонічні SurfaceContext, device capabilities, native bridge, device registry, client version та deep-link contracts COS.
status: active
updated: 2026-09-21
kind: architecture
---

# Контракти для нативної оболонки

Wave 12.18 робить Symfony Experience Platform готовою до майбутньої нативної оболонки без створення окремої мобільної business architecture.

## Межа відповідальності

```text
Business UI
   ↓
SurfaceContext + DeviceCapabilities
   ↓
NativeBridge contracts
   ↓
майбутній iOS / Android adapter
```

Application Commands, Queries, permissions і tenant context лишаються канонічними незалежно від surface.

Нативна оболонка не отримує окрему модель бізнес-дій.

## Контекст поверхні

`SurfaceContext` має лише канонічні значення:

```text
web_desktop
web_mobile
pwa
native_ios
native_android
```

Це не user-agent sniffing і не визначення моделі пристрою.

Surface описує delivery context, тоді як доступність конкретної можливості визначається через `DeviceCapabilities`.

## Можливості пристрою

Канонічний набір:

```text
camera
geolocation
push
biometrics
share
filesystem
contacts
haptics
barcode
```

Business UI повинен перевіряти capability, а не `navigator.userAgent`, назву ОС або ширину екрана.

## Контракт нативного мосту

`NativeBridgeInterface` визначає:

- surface;
- capabilities;
- runtime availability.

Окремі capability contracts:

- `CameraBridgeInterface`;
- `LocationBridgeInterface`;
- `ShareBridgeInterface`;
- `NotificationBridgeInterface`;
- `BiometricBridgeInterface`;
- `FileBridgeInterface`;
- `BarcodeBridgeInterface`;
- `HapticBridgeInterface`.

Wave 12.18 навмисно не фіксує platform-SDK method signatures. Конкретні camera/location/file DTO та transport semantics мають бути зафіксовані під час native pilot через окремий ADR, коли з’явиться реальний runtime.

Це не дозволяє випадково зробити Capacitor, Hotwire Native, Swift або Kotlin hard dependency базової Web Platform.

## Реєстр пристроїв

Application layer визначає `DeviceRegistryInterface`.

Модель `UserDevice` містить:

- device id;
- user id;
- platform;
- app version;
- device name;
- push token;
- capabilities;
- last seen;
- revocation timestamp.

Wave 12.18 створює contract і model, але не додає persistence table без production native requirement.

Реєстрація, refresh/revoke token flow та push-provider binding мають використовувати цей contract, а не Web session state.

## Сумісність версій

`ClientVersionPolicy` порівнює:

```text
ClientVersion
MinimumSupportedVersion
RecommendedVersion
RequiredCapabilities
```

Результат:

```text
supported
upgrade_recommended
upgrade_required
```

Відсутність обов’язкової capability також дає `upgrade_required`, навіть якщо semantic version достатня.

## Глибокі посилання

Canonical entity identity лишається `EntityRef`.

`EntityLinkResolver` агрегує module-owned `web.entity_links` providers і вимагає рівно один canonical Web link.

`DeepLinkResolver` проектує:

```text
EntityRef
   ↓
canonical Web path
   +
cos://entity/{type}/{id}
```

Таким чином Search, Notifications, AI, Activity та майбутній mobile push можуть посилатися на одну entity identity.

## Безпека

Biometrics не замінює backend authentication.

Device capability не є authorization.

Native shell не може:

- довіряти tenant id із client payload;
- виконувати Commands в обхід Application layer;
- зберігати permissions як авторитетний local state;
- робити platform sniffing основою business behavior.

## Межа поточної хвилі

Wave 12.18 не створює:

- production iOS app;
- production Android app;
- native SDK dependency;
- push-provider integration;
- biometric authorization flow;
- device persistence migration;
- offline command queue.

Вона створює стабільні contracts, на які ці реалізації можуть спиратися без дублювання COS architecture.
