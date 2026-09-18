# Symfony-native Doctrine migrations

This directory is authoritative only for **new Symfony-native schema** managed through Doctrine Migrations.

Existing `app/migrations/*.sql` and legacy tables remain on their current migration path until a bounded vertical slice is deliberately moved. Do not convert the whole legacy schema into Doctrine migrations in one change.
