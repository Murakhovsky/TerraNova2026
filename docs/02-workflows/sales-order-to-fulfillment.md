---
title: Sales Order → виконання замовлення
description: Керований цикл замовлення з атомарним резервуванням, виконанням і поверненням через Product Management.
status: active
updated: 2026-11-25
kind: workflow
contract: workflow-v2
process_state: as-is
process_id: sales.order-to-fulfillment
---

# Sales Order → виконання замовлення

## Бізнес-мета

Sales володіє Order, OrderLine, allocation references, revision і бізнесовими фактами виконання. Product Management є власником E5 reservation, stock balance, movement ledger та фізичного повернення. Внутрішні stock operations не є окремими публічними командами для цього процесу; вони викликаються тільки через `OrderInventoryPort` у транзакції Sales. Не створюється другий склад або executor.

## Учасники

- Авторизований менеджер Sales, який підтверджує бізнесові документи.
- Sales Application і Product Management як транзакційні власники власного стану.

## Процес

<ProcessDiagram process-id="sales.order-to-fulfillment" />

## Контроль виконання

- Ініціатор: авторизований менеджер через Capability Gateway.
- Очікувана revision, idempotency key та унікальний документ на операцію. Повторний ключ не повторює бізнесовий ефект.
- Резервування частини кількості на кількох складах, деалокація, часткове виконання, скасування залишку, повернення зі складським оприбуткуванням або без нього.
- Product-owned рухи і Sales evidence в одній транзакції; помилка будь-якого рядка відкочує весь command.
- Невідомий Product, відсутність stock, чужий tenant, застаріла revision або перевищення поверненої кількості відхиляються.
- Shopify orders: локальні складські операції заблоковані до verified ownership cutover. Provider observation не є локальним резервом чи доказом відвантаження.
- Payment і refund не створюються цим процесом. Фінансові факти належать Finance.

## Докази

`tests/integration/sales_workspace_summary_mysql.php` перевіряє actual MySQL stock ledger, atomic rollback, two-connection locking та replay. Реальне provider acceptance і release promotion залишаються окремими воротами.

## Карта коду

- `app/Domains/Sales/Application/Service/SalesOrderLifecycleService.php`
- `app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesOrderRepository.php`
- `app/Domains/ProductManagement/Application/Contract/OrderInventoryPort.php`
- `app/Domains/ProductManagement/Infrastructure/Persistence/MySqlProductManagementStore.php`
- `resources/processes/sales-order-to-fulfillment.json`
