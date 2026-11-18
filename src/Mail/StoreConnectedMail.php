<?php

namespace Omarsenusi\MarketplacePlatforms\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Omarsenusi\MarketplacePlatforms\Models\PlatformConnection;
use Omarsenusi\MarketplacePlatforms\Support\PublicUrl;

/**
 * Sent when the store owner's email already belongs to an existing
 * user: no new account, just a new workspace — so a normal login
 * link, not a claim link.
 */
class StoreConnectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Model $user,
        public ?PlatformConnection $platformConnection = null,
    ) {}

    public function envelope(): Envelope
    {
        $brand = (string) (config('platforms.mail.brand_name') ?? config('app.name'));

        return new Envelope(
            subject: $brand.' — '.__('A store was connected to your account'),
        );
    }

    public function content(): Content
    {
        $connection = $this->platformConnection;
        if ($connection !== null) {
            $connection->loadMissing(['platform', 'storeSettings', 'workspace']);
        }

        return new Content(
            markdown: 'platforms::mail.store-connected',
            with: [
                'user' => $this->user,
                'connection' => $connection,
                'storeName' => $connection?->storeSettings?->store_name ?? __('your store'),
                'platformName' => $connection?->platform?->name,
                'workspaceName' => $connection?->workspace?->name,
                'loginUrl' => PublicUrl::route('login'),
            ],
        );
    }
}
