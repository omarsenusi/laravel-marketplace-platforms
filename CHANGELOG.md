# Changelog

All notable changes to this project will be documented in this file.

## 0.1.0 - unreleased

- Initial standalone extraction: Salla and Zid drivers (easy-mode and
  custom OAuth, token exchange/refresh, store profiles, embedded launch
  tokens, subscription event parsing).
- Token storage with encrypted casts, refresh scheduling with jitter,
  and per-connection refresh locks.
- Verified webhook ingress (hmac_sha256, plain_header, bearer_token,
  static_header, basic_auth_password, composite_any, none) with header
  redaction, idempotent delivery storage, queued processing with
  backoff and dead-lettering.
- Store provisioning and claim links via host `ProvisionsAccounts`
  contract; subscription application via host `AppliesSubscriptions`
  contract (with a recording `NullSubscriptionApplier` default).
- Domain events for every lifecycle step (`StoreProvisioned`,
  `StoreReinstalled`, `StoreUninstalled`, `StoreProfileRefreshed`,
  `TokensRefreshed`, `SubscriptionChanged`, `WorkspaceClaimed`,
  `PlatformWebhookReceived`).
- Catalog and order readers (`CatalogReader(s)`, Salla/Zid readers,
  `OrderLookup`, Salla/Zid lookups) behind the per-store
  `StoreApiGateway` rate limiter (lanes `realtime`/`background`,
  shares under `platforms.api.lanes`).
- Commerce write capabilities (`src/Commerce`): `ProductWriter`,
  `InventoryWriter`, `ShipmentWriter`, `SettlementGateway`,
  `OrderWriter`, `ShippingSetup`, `CategoryWriter`,
  `TransactionWriter` and `CouponWriter` with Salla implementations,
  per-driver registries, minor-unit money DTOs, single-flight 401
  refresh and no write auto-retries; results carry Salla error codes,
  validation fields and pacing headers.
