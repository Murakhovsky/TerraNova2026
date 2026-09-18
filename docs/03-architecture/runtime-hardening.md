---
title: Надійність Symfony runtime
description: Graceful shutdown і deployment readiness для worker та scheduler.
status: active
updated: 2026-09-18
kind: architecture
contract: architecture-v1
---

# Надійність Symfony runtime

## 38. Посилення надійності runtime

`worker` і `scheduler` є окремими first-class processes одного Symfony image.

Для них встановлено:

- `init: true`;
- `stop_grace_period: 30s`;
- bounded `--time-limit`;
- restart policy;
- deployment guard: container повинен бути running і мати `RestartCount = 0` після async probe.

Deployment не оголошується успішним, якщо worker або scheduler стартував, упав і був тихо піднятий Docker-ом. “Self-healing” чудова назва, але CI все одно має знати, що спочатку щось померло.

## Критерії готовності

Runtime вважається готовим після deploy лише коли HTTP health проходить, async probe оброблений, worker і scheduler залишаються у стані running, а їхній restart counter дорівнює нулю. Сам факт автоматичного restart не вважається успішним запуском.
