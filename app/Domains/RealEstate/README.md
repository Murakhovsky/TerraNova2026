# RealEstate Domain

RealEstate V0.2 owns brokerage orchestration over the canonical Property registry.

## Business flow

```text
Sales Opportunity
      ↓
Property Match
      ↓
Offer
      ↓
Viewing
      ↓
Reservation
```

## Ownership

RealEstate owns:

- brokerage cases;
- property matches bound to a Sales opportunity;
- offers;
- viewings;
- brokerage workflow state.

RealEstate does **not** own Property assets, Inventory or Listings. It reads Property through `PropertyReferencePort` and requests inventory reservations through `PropertyInventoryCommandInterface`.

RealEstate does **not** read Sales tables. Sales opportunity existence is checked through `SalesOpportunityReferenceInterface`.

## Runtime

The V0.2 runtime contributes `realEstateDomainModule`, tenant-scoped MySQL persistence and canonical domain events.

Consequential writes are retry-safe:

- Property Match uses a deterministic business identity plus a tenant-scoped unique constraint;
- Offer and Viewing use deterministic request identities and atomic `INSERT IGNORE`;
- Reservation is serialized by the Property-owned Inventory row lock and remains transactional with brokerage state/event writes.

## Symfony API

- `POST /api/v1/sales/opportunities/{id}/property-matches`
- `GET /api/v1/real-estate/cases/{id}`
- `POST /api/v1/real-estate/cases/{id}/offers`
- `POST /api/v1/real-estate/cases/{id}/viewings`
- `POST /api/v1/real-estate/cases/{id}/reservation`

Session-authenticated mutations require CSRF and `X-Idempotency-Key`.
