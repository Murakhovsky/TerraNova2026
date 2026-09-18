# Doctrine persistence entities

Only persistence models for **new Symfony-native storage** belong here.

Domain models stay framework-independent under `app/Domains`. Doctrine entities and repository adapters translate between storage records and Domain/Application contracts. Legacy tables are never retrofitted into ORM entities merely to make migration look tidy.
