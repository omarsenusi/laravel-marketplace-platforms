<?php

namespace Omarsenusi\MarketplacePlatforms\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Omarsenusi\MarketplacePlatforms\Auth\ClaimService;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Models\WorkspaceClaim;
use Omarsenusi\MarketplacePlatforms\Support\PublicUrl;

/**
 * Arabic-first (English below): markdown layout via the package's
 * mail view namespace so the `mail::` components resolve.
 */
class ClaimWorkspaceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public string $claimUrl;

    public function __construct(
        public Model $user,
        public WorkspaceClaim $claim,
        public string $rawToken,
        public ?PlatformConnection $platformConnection = null,
    ) {
        $this->claimUrl = PublicUrl::route('platforms.claim.show', ['token' => $rawToken]);
    }

    public function envelope(): Envelope
    {
        $storeName = $this->storeName();
        $brand = (string) (config('platforms.mail.brand_name') ?? config('app.name'));

        return new Envelope(
            subject: $brand.' — '.__('Set up your workspace for :store', ['store' => $storeName]),
        );
    }

    public function content(): Content
    {
        $this->claim->loadMissing('workspace');
        $connection = $this->platformConnection;
        if ($connection !== null) {
            $connection->loadMissing(['platform', 'storeSettings']);
        }

        return new Content(
            markdown: 'platforms::mail.claim-workspace',
            with: [
                'user' => $this->user,
                'claim' => $this->claim,
                'claimUrl' => $this->claimUrl,
                'storeName' => $this->storeName(),
                'workspaceName' => $this->claim->workspace?->name,
                'platformName' => $connection?->platform?->name,
                'maskedEmail' => ClaimService::maskEmail($this->user->getAttribute('email')),
                'expiryHours' => (int) config('platforms.claim.ttl_hours', 72),
            ],
        );
    }

    private function storeName(): string
    {
        $connection = $this->platformConnection;
        if ($connection !== null) {
            $connection->loadMissing('storeSettings');
        }

        return $connection?->storeSettings?->store_name
            ?? $this->claim->workspace?->name
            ?? __('your store');
    }
}
