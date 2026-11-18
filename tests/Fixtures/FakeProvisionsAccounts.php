<?php

namespace Omarsenusi\MarketplacePlatforms\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Omarsenusi\MarketplacePlatforms\Auth\StoreProfile;
use Omarsenusi\MarketplacePlatforms\Contracts\ProvisionsAccounts;

/**
 * Test implementation of the host account contract: creates fixture
 * users/workspaces with the same semantics the package requires (null
 * password until claimed, owner pointer on the workspace).
 */
final class FakeProvisionsAccounts implements ProvisionsAccounts
{
    public function findUserByEmail(string $email): ?Model
    {
        $userClass = (string) config('platforms.models.user');

        return $userClass::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
    }

    public function createStoreOwner(StoreProfile $profile, string $email): Model
    {
        $userClass = (string) config('platforms.models.user');

        $name = trim((string) ($profile->ownerName ?? ''));
        if ($name === '') {
            $name = trim((string) ($profile->storeName ?? ''));
        }
        if ($name === '') {
            $name = 'Store owner';
        }

        return $userClass::create([
            'name' => mb_substr($name, 0, 255),
            'email' => $email,
            'phone' => is_string($profile->ownerPhone) ? mb_substr($profile->ownerPhone, 0, 32) : null,
            'locale' => $profile->locale ?? 'ar',
        ]);
    }

    public function createWorkspaceFor(Model $user, StoreProfile $profile): Model
    {
        $workspaceClass = (string) config('platforms.models.workspace');

        $name = trim((string) ($profile->storeName ?? ''));

        $workspace = $workspaceClass::create([
            'name' => $name !== '' ? mb_substr($name, 0, 120) : 'My Store',
            'owner_user_id' => $user->getKey(),
        ]);

        $user->forceFill(['default_workspace_id' => $workspace->getKey()])->save();

        return $workspace->refresh();
    }

    public function workspaceOwner(?Model $workspace): ?Model
    {
        if ($workspace === null) {
            return null;
        }

        $userClass = (string) config('platforms.models.user');

        return $userClass::query()->find($workspace->getAttribute('owner_user_id'));
    }
}
