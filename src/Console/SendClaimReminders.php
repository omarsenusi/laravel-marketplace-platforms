<?php

namespace Omarsenusi\MarketplacePlatforms\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Enums\ConnectionStatus;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;

/**
 * Daily claim reminders: for unclaimed users whose connection is
 * still active, send a fresh claim email at day 1, 3 and 7 after the
 * FIRST claim (reminder_count 0→1→2→3), never more than 3. Each
 * reminder invalidates older tokens.
 */
class SendClaimReminders extends Command
{
    protected $signature = 'platforms:claims:remind';

    protected $description = 'Send workspace-claim reminder emails (days 1, 3, 7)';

    private const REMINDER_DAYS = [1, 3, 7];

    public function __construct(private readonly ClaimService $claims)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sent = 0;

        $userIds = WorkspaceClaim::query()
            ->whereNull('used_at')
            ->select('user_id')
            ->distinct()
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            $userClass = (string) config('platforms.models.user');
            $user = $userClass::query()->find($userId);

            if ($user === null || WorkspaceClaim::isClaimed($user)) {
                continue;
            }

            $first = WorkspaceClaim::query()
                ->where('user_id', $userId)
                ->orderBy('created_at')
                ->first();

            if ($first === null) {
                continue;
            }

            $latest = WorkspaceClaim::query()
                ->where('user_id', $userId)
                ->orderByDesc('created_at')
                ->first();

            if ($latest === null || ($latest->reminder_count ?? 0) >= 3) {
                continue;
            }

            $daysSince = $first->created_at->diffInDays(now());
            $nextIndex = (int) $latest->reminder_count;

            if (! isset(self::REMINDER_DAYS[$nextIndex]) || $daysSince < self::REMINDER_DAYS[$nextIndex]) {
                continue;
            }

            if (! $this->hasActiveConnection($user)) {
                continue;
            }

            $connection = $user->default_workspace_id
                ? PlatformConnection::query()->where('workspace_id', $user->default_workspace_id)->first()
                : null;

            // issueAndSend threads the raw token through (only the
            // sha256 is stored, so it cannot be recovered later).
            ['claim' => $claim] = $this->claims->issueAndSend($user, $connection, null);
            $claim->forceFill(['reminder_count' => $nextIndex + 1])->save();

            $sent++;
        }

        $this->info("Sent {$sent} claim reminder(s).");

        return self::SUCCESS;
    }

    private function hasActiveConnection(Model $user): bool
    {
        if ($user->getAttribute('default_workspace_id') === null) {
            return false;
        }

        return PlatformConnection::query()
            ->where('workspace_id', $user->getAttribute('default_workspace_id'))
            ->where('status', ConnectionStatus::Active)
            ->exists();
    }
}
