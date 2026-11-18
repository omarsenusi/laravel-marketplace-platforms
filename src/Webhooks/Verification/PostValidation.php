<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification;

use Omarsenusi\MarketplacePlatforms\Models\Platform;

/**
 * Optional post-validation applied after a strategy succeeds.
 *
 * verification_config.postValidation = [
 *   'id' => 'payload_app_id',
 *   'config' => ['jsonPath' => 'app_id', 'allowedRefs' => ['app_id']],
 * ]
 *
 * Dots into the decoded JSON payload and requires the value to equal one
 * of the resolved refs (same resolver as strategies: secrets + app_id /
 * client_id from the platform row). Returns null when valid, otherwise a
 * human-readable reason.
 */
final class PostValidation
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public static function check(Platform $platform, ?array $payload, mixed $postValidation): ?string
    {
        if ($postValidation === null) {
            return null;
        }

        if (! is_array($postValidation) || ($postValidation['id'] ?? null) !== 'payload_app_id') {
            return "unsupported postValidation id '".(is_array($postValidation) ? ($postValidation['id'] ?? '?') : '?')."'";
        }

        $config = $postValidation['config'] ?? null;

        if (! is_array($config)) {
            return 'postValidation config must be an object';
        }

        $jsonPath = $config['jsonPath'] ?? null;
        $allowedRefs = $config['allowedRefs'] ?? [];

        if (! is_string($jsonPath) || trim($jsonPath) === '' || ! is_array($allowedRefs) || $allowedRefs === []) {
            return 'postValidation requires config.jsonPath and a non-empty config.allowedRefs';
        }

        $value = data_get($payload ?? [], $jsonPath);

        if (! is_string($value) || $value === '') {
            return "payload path '{$jsonPath}' is missing or empty";
        }

        $resolver = SecretResolver::for($platform);

        foreach ($allowedRefs as $ref) {
            $expected = $resolver((string) $ref);

            if (is_string($expected) && $expected !== '' && hash_equals($expected, $value)) {
                return null;
            }
        }

        return "payload '{$jsonPath}' does not match any allowed reference";
    }
}
