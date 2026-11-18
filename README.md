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
