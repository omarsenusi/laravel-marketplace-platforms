<?php

namespace Omarsenusi\MarketplacePlatforms\Contracts;

use Illuminate\Database\Eloquent\Model;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;

/**
 * Host-side account provisioning.
 *
 * The package owns the marketplace connection (tokens, settings,
 * claims); the host app owns users and workspaces. Bind your own
 * implementation in a service provider.
 *
 * The user model returned must expose `id`, `email`, `password`
 * (null until claimed), `locale` (nullable) and
 * `default_workspace_id` (nullable) attributes.
 */
interface ProvisionsAccounts
{
    /**
     * Case-insensitive email lookup. Return null when unknown.
     */
    public function findUserByEmail(string $email): ?Model;

    /**
     * Create the store-owner user for a fresh install. The user must
     * NOT be able to authenticate yet (null/unguessable password);
     * claiming sets the password later.
     */
    public function createStoreOwner(StoreProfile $profile, string $email): Model;

    /**
     * Create a new workspace owned by the given user for the store
     * described by the profile, and return it.
     */
    public function createWorkspaceFor(Model $user, StoreProfile $profile): Model;

    /**
     * The owner user of the given workspace model, or null.
     */
    public function workspaceOwner(?Model $workspace): ?Model;
}
