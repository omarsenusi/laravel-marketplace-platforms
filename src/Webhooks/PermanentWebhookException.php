<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks;

/**
 * Throw from a WebhookHandler when retrying is pointless. The delivery
 * goes straight to dead_letter instead of burning through max_attempts.
 */
class PermanentWebhookException extends \RuntimeException {}
