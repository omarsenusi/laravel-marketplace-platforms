# omarsenusi/laravel-marketplace-platforms

Salla and Zid integration for Laravel: easy-mode OAuth, token storage and
refresh, verified webhook ingress, store provisioning and claim links,
catalog and order readers.

## What it does

- **Platform drivers** for Salla (easy install + custom OAuth mode) and
  Zid (OAuth): authorization-code exchange, token refresh, store
  profile reads, embedded-launch token verification, and marketplace
  subscription event parsing.
- **Token storage** on `platform_connections` (encrypted casts, absolute
  expiry + jittered refresh schedule columns) with per-connection
  refresh locks and a due-refresh dispatcher.
- **Verified webhook ingress**: `POST /webhooks/platforms/{platform}/{key}`
  verifies signatures (hmac_sha256, plain_header, bearer_token,
  static_header, basic_auth_password, composite_any, none), redacts
  secrets, stores one delivery row per event (idempotent on
  `external_event_id`), and queues `ProcessPlatformWebhook` with
  backoff + dead-lettering. Handled topics run registered handlers;
  every processed delivery also fires `PlatformWebhookReceived`.
- **Store provisioning and claim links**: install/authorize webhooks and
  the OAuth callback create (or reactivate) the connection, attach the
  host's user + workspace through the `ProvisionsAccounts` contract,
  and email a signed claim link (`/claim/{token}`) that sets the owner's
  password. Uninstall clears tokens, expires claims, and keeps the
  account rows.
- **Marketplace subscriptions**: normalized snapshots are persisted on
  the connection, `platform_plan_mappings` resolves the host plan id,
  and the bound `AppliesSubscriptions` implementation applies the
  change. Without a binding, `NullSubscriptionApplier` records the
  snapshot and flags it as needing action.
- **Catalog and order readers** (`CatalogReader(s)`, Salla/Zid catalog
  readers, `OrderLookup`, Salla/Zid order lookups) behind the
  per-store `StoreApiGateway` rate limiter.
- **Commerce writers** (`src/Commerce`): typed Salla write
  capabilities — products, inventory, shipments, instant settlements,
  order statuses, shipping setup, categories, transactions, coupons —
  behind the same gateway with single-flight 401 refresh and no write
  auto-retries.

## Install

```bash
composer require omarsenusi/laravel-marketplace-platforms
```

The service provider is auto-discovered. Publish the config:

```bash
php artisan vendor:publish --tag=platforms-config
```

Run the migrations with your app's normal `php artisan migrate`. The
package ships an `inertiajs/inertia-laravel` dependency because the
claim, launch, and OAuth-callback controllers render Inertia pages in
your app's `auth/claim-workspace`, `platforms/launch`,
`platforms/launch-embedded`, and `platforms/connect-error` components.

Requirements: PHP ^8.3, Laravel (illuminate components) ^12.0|^13.0.

## Configuration

All keys live in `config/platforms.php` (`PLATFORMS_` env prefix):

| Key | Purpose |
| --- | ------- |
| `models.user` / `models.workspace` | Host Eloquent models (defaults `App\Models\User`, `App\Models\Workspace`) |
| `tables.users` / `tables.workspaces` | Host table names for foreign keys (defaults `users`, `workspaces`) |
| `webhooks.queue` / `webhooks.throttle` | Queue + rate limit for webhook ingress |
| `claim.ttl_hours` / `claim.max_reminders` | Claim-link lifetime + reminder cap |
| `claim.home` | Where to redirect after a successful claim (default `/dashboard`) |
| `embedded.frame_ancestors` | CSP `frame-ancestors` for the launch response |
| `refresh.lock_wait_seconds` | Refresh-lock wait before requeueing |
| `api.rate_limits` | Per-driver per-minute outbound budgets |
| `mail.brand_name` | Brand in mail subjects (default `config('app.name')`) |

Key types must match: `platform_connections.workspace_id` is a uuid
foreign key and `workspace_claims.user_id` is a bigint foreign key, so
host workspaces need a uuid primary key and host users a bigint primary
key. The `000007` migration alters the host's users table (nullable
password + nullable phone, both guarded by `hasTable`/`hasColumn`);
skip it if your users table differs.

The package reads these user attributes: `id`, `email`, `password`
(null until claimed), `locale` (nullable), `default_workspace_id`
(nullable), `email_verified_at` (nullable).

## Required host contracts

**Accounts** — bind `Contracts\ProvisionsAccounts` so installs can
create owners and workspaces:

```php
$this->app->singleton(
    \Omarsenusi\MarketplacePlatforms\Contracts\ProvisionsAccounts::class,
    \App\Marketplace\MarketplaceAccountProvisioner::class, // your class
);
```

Without a binding, provisioning throws a `LogicException` naming the
contract. The four methods (`findUserByEmail`, `createStoreOwner`,
`createWorkspaceFor`, `workspaceOwner`) are documented on the
interface.

**Subscriptions** — bind `Contracts\AppliesSubscriptions` to change
host plans when marketplace events arrive (`apply` / `lapse`). Without
a binding, `NullSubscriptionApplier` records the snapshot and reports
it as needing action.

## Events

The package never writes host audit rows; it dispatches events and the
host listens:

| Event | When |
| --- | ---- |
| `StoreProvisioned` | New connection row created (`$isNewUser`) |
| `StoreReinstalled` | Existing connection reactivated |
| `StoreUninstalled` | Connection uninstalled |
| `StoreProfileRefreshed` | Settings re-synced from the profile |
| `TokensRefreshed` | Tokens created or refreshed |
| `SubscriptionChanged` | Subscription event applied/recorded (`$outcome`, `$needsAction`) |
| `WorkspaceClaimed` | Claim completed (`via`: `claim_page`, `password_reset`) |
| `PlatformWebhookReceived` | Every processed delivery, handled or not (platform slug, driver, topic, store id, payload, connection) |

## Webhook handlers

Register handlers for marketplace topics (patterns are `fnmatch()`,
first match wins; unknown topics fall back to `NullHandler`):

```php
use Omarsenusi\MarketplacePlatforms\Webhooks\WebhookHandlerRegistry;

app(WebhookHandlerRegistry::class)->register(
    'salla', 'order.*', MyOrderHandler::class,
);
```

The defaults cover Salla `app.store.authorize`, `app.installed`,
`app.uninstalled`, `app.updated`, subscription/trial events, and Zid
install/authorized/uninstall plus subscription events.

## Writing to stores

The `src/Commerce` capabilities push data into Salla stores through the
single outbound path (`StoreApiGateway`). One interface per capability,
one Salla implementation each, resolved by driver:

```php
use Omarsenusi\MarketplacePlatforms\Commerce\ProductWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\Money;

$writer = ProductWriters::for($connection); // Salla impl; Zid throws UnsupportedOperation

$result = $writer->create($connection, new ProductInput(
    name: 'T-Shirt Blue',
    price: new Money(9633, 'SAR'), // halalas; sent as 96.33
    productType: 'product',
    quantity: 4,
    categoryIds: [$platformCategoryId],
));

if (! $result->ok) {
    // $result->status, $result->errorCode, $result->fields (422 details)
}
```

**Money convention.** Every DTO carries money as integer minor units
(halalas) plus an ISO currency (`Money{amountMinor, currency}`). The
Salla implementation converts to decimal amounts (2 dp) at the HTTP
edge and parses `{amount, currency}` back on read. Never pass floats.

**No auto-retry for writes.** `POST`/`PUT`/`PATCH`/`DELETE` are not
assumed idempotent: a 5xx/429 is returned as a failure (status, error
code, validation `fields`, pacing headers) and the caller decides. The
only automatic retry is a single token refresh + one retry on 401.

More examples:

```php
use Omarsenusi\MarketplacePlatforms\Commerce\InventoryWriters;
use Omarsenusi\MarketplacePlatforms\Commerce\StockAdjustment;
use Omarsenusi\MarketplacePlatforms\Commerce\StockIdentifierType;
use Omarsenusi\MarketplacePlatforms\Commerce\StockMode;

// Stock sync across many stores (chunked; Salla queues the update).
// Pass the mode explicitly: increment/decrement deltas are
// concurrency-safe, overwrite only for an initial load. On a partial
// failure the result still carries a report — retry only its pending
// adjustments, never the whole list.
InventoryWriters::for($connection)->adjust($connection, [
    new StockAdjustment(StockIdentifierType::Sku, 'TSH-1', 5, StockMode::Decrement),
]);

// Shipment for an order.
use Omarsenusi\MarketplacePlatforms\Commerce\NationalAddress;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipFromBranch;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentInput;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentPaymentMethod;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentPackage;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentType;
use Omarsenusi\MarketplacePlatforms\Commerce\ShipmentWriters;

ShipmentWriters::for($connection)->create($connection, new ShipmentInput(
    orderId: 560695738, courierId: 814202285,
    type: ShipmentType::Shipment, paymentMethod: ShipmentPaymentMethod::Cod,
    packages: [new ShipmentPackage(name: 'T-Shirt', quantity: 2)],
    shipFrom: new ShipFromBranch($branchId), shipTo: $nationalAddress,
));

// Hold funds for a dropshipping order (dropshipping-approved apps only).
use Omarsenusi\MarketplacePlatforms\Commerce\SettlementGateways;

SettlementGateways::for($connection)->create($connection, $orderReferenceId, new Money(15335, 'SAR'));

// Move an order to a status (slug or numeric status id).
use Omarsenusi\MarketplacePlatforms\Commerce\OrderWriters;

OrderWriters::for($connection)->updateStatus($connection, $orderId, slug: 'delivering');

// Reconciliation polling (from/to filter by creation date; sorted by update).
OrderWriters::for($connection)->listSince($connection, now()->subDay(), page: 1);

// Custom shipping company + zone for the platform catalog.
use Omarsenusi\MarketplacePlatforms\Commerce\ShippingSetups;
use Omarsenusi\MarketplacePlatforms\Commerce\ZoneCashOnDelivery;
use Omarsenusi\MarketplacePlatforms\Commerce\ZoneFee;
use Omarsenusi\MarketplacePlatforms\Commerce\ZoneFeeType;
use Omarsenusi\MarketplacePlatforms\Commerce\ZoneInput;

$company = ShippingSetups::for($connection)->createCompany($connection, 'Platform Logistics');
ShippingSetups::for($connection)->createZone($connection, (int) $company->data->id, new ZoneInput(
    fees: new ZoneFee(ZoneFeeType::Rate, 1100),
    duration: '3-5',
    cashOnDelivery: new ZoneCashOnDelivery(true, 1200),
));

// Hidden platform-products category.
use Omarsenusi\MarketplacePlatforms\Commerce\CategoryInput;
use Omarsenusi\MarketplacePlatforms\Commerce\CategoryStatus;
use Omarsenusi\MarketplacePlatforms\Commerce\CategoryWriters;

CategoryWriters::for($connection)->create($connection, new CategoryInput(
    name: 'Platform products', status: CategoryStatus::Hidden,
));

// Partial refund of a transaction.
use Omarsenusi\MarketplacePlatforms\Commerce\TransactionAction;
use Omarsenusi\MarketplacePlatforms\Commerce\TransactionWriters;

TransactionWriters::for($connection)->update(
    $connection, $transactionId, TransactionAction::Refund, new Money(9000, 'SAR')
);

// Single-use coupon for one customer.
use Omarsenusi\MarketplacePlatforms\Commerce\CouponInput;
use Omarsenusi\MarketplacePlatforms\Commerce\CouponType;
use Omarsenusi\MarketplacePlatforms\Commerce\CouponWriters;

CouponWriters::for($connection)->createSingleUse($connection, new CouponInput(
    code: 'WELCOME10', type: CouponType::Fixed, customerIds: [$customerId], amountMinor: 1000,
));
```

### OAuth scopes

| Capability | Read scope | Write scope |
| ---------- | ---------- | ----------- |
| `ProductWriter` | `products.read` (quantities read) | `products.read_write` |
| `InventoryWriter` | `products.read` | `products.read_write` |
| `ShipmentWriter` | `shipping.read` | `shipping.read_write` |
| `SettlementGateway` | `settlements.read` | `settlements.read_write` (dropshipping-approved apps only) |
| `OrderWriter` | `orders.read` | `orders.read_write` |
| `ShippingSetup` | `shipping.read` | `shipping.read_write` (routes need Salla approval) |
| `CategoryWriter` | `categories.read` | `categories.read_write` |
| `TransactionWriter` | `transactions.read` | `transactions.read_write` |
| `CouponWriter` | — | `marketing.read_write` |

## Routes

| Method | URI | Name |
| ------ | --- | ---- |
| POST | `/webhooks/platforms/{platformSlug}/{endpointKey}` | `platforms.webhooks.ingress` |
| GET | `/platforms/{slug}/callback` | `platforms.callback` |
| GET\|POST | `/platforms/{slug}/launch` | `platforms.launch` |
| GET | `/claim/{token}` | `platforms.claim.show` |
| POST | `/claim/{token}` | `platforms.claim.store` |
| POST | `/claim/{token}/resend` | `platforms.claim.resend` |

The host app must provide `login` and `dashboard` named routes (claim
and launch flows redirect to them).

## Commands

| Command | Schedule |
| ------- | -------- |
| `platforms:webhooks:purge` | hourly — delete deliveries past `expires_at` |
| `platforms:tokens:refresh-due` | every 15 minutes — dispatch due refreshes |
| `platforms:claims:remind` | daily — claim reminders at days 1/3/7, max 3 |

## Development

```bash
composer update
vendor/bin/pint --test
vendor/bin/pest
```

Tests run on Orchestra Testbench (sqlite in-memory) with minimal
`User`/`Workspace` fixtures and fake contract bindings under
`tests/Fixtures`.

## License

MIT © 2026 Omar Senusi. Provided as-is, with no support guarantee.
