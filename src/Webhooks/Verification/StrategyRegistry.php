<?php

namespace Omarsenusi\MarketplacePlatforms\Webhooks\Verification;

use InvalidArgumentException;

/**
 * Registry of verification strategies, shared as an app singleton.
 * Custom strategies register here from a service provider.
 */
class StrategyRegistry
{
    /**
     * @var array<string, VerificationStrategy>
     */
    private array $strategies = [];

    public function register(VerificationStrategy $strategy): void
    {
        $this->strategies[$strategy->id()] = $strategy;
    }

    public function get(string $id): ?VerificationStrategy
    {
        return $this->strategies[$id] ?? null;
    }

    public function resolve(string $id): VerificationStrategy
    {
        $strategy = $this->get($id);

        if ($strategy === null) {
            throw new InvalidArgumentException("Unknown webhook verification strategy [{$id}].");
        }

        return $strategy;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->strategies);
    }

    /**
     * @return list<array{id: string, label: string, description: string, fields: list<array<string, mixed>>}>
     */
    public function list(): array
    {
        return array_map(
            fn (VerificationStrategy $strategy) => array_merge(['id' => $strategy->id()], $strategy->describe()),
            array_values($this->strategies),
        );
    }

    /**
     * Validate an endpoint verification config against a strategy's
     * describe() schema. Returns a flat list of human-readable errors
     * (empty = valid). Composite children are validated recursively
     * (max depth 2).
     *
     * @return list<string>
     */
    public function validateConfig(string $strategyId, mixed $config, int $depth = 0): array
    {
        $strategy = $this->get($strategyId);

        if ($strategy === null) {
            return ["Unknown verification strategy [{$strategyId}]."];
        }

        if (! is_array($config)) {
            return ['Verification config must be an object.'];
        }

        $errors = [];
        $fields = $strategy->describe()['fields'] ?? [];

        foreach ($fields as $field) {
            $key = (string) ($field['key'] ?? '');
            $type = (string) ($field['type'] ?? 'text');
            $required = (bool) ($field['required'] ?? false);
            $value = $config[$key] ?? null;

            if ($key === '') {
                continue;
            }

            if ($type === 'list') {
                $children = $value ?? [];

                if ($required && (! is_array($children) || $children === [])) {
                    $errors[] = "Config '{$key}' must be a non-empty list of child strategies.";

                    continue;
                }

                if (! is_array($children)) {
                    $errors[] = "Config '{$key}' must be a list.";

                    continue;
                }

                if ($depth >= 2) {
                    $errors[] = 'Composite strategies may nest at most 2 levels deep.';

                    continue;
                }

                foreach (array_values($children) as $index => $child) {
                    if (! is_array($child) || ! isset($child['id']) || ! is_string($child['id'])) {
                        $errors[] = 'Child #'.($index + 1).' must define a strategy id.';

                        continue;
                    }

                    foreach ($this->validateConfig($child['id'], $child['config'] ?? [], $depth + 1) as $childError) {
                        $errors[] = 'Child #'.($index + 1)." ({$child['id']}): {$childError}";
                    }
                }

                continue;
            }

            if ($required && ($value === null || $value === '')) {
                $errors[] = "Config '{$key}' is required.";

                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            match ($type) {
                'text' => is_string($value) || $errors[] = "Config '{$key}' must be a string.",
                'select' => in_array($value, $field['options'] ?? [], true) || $errors[] = "Config '{$key}' must be one of: ".implode(', ', $field['options'] ?? []).'.',
                'bool' => is_bool($value) || $errors[] = "Config '{$key}' must be a boolean.",
                'secret_ref' => in_array($value, self::allowedRefs(), true) || $errors[] = "Config '{$key}' must be one of: ".implode(', ', self::allowedRefs()).'.',
                default => null,
            };
        }

        // Optional post-validation block: {id, config:{jsonPath, allowedRefs}}.
        if (isset($config['postValidation'])) {
            $post = $config['postValidation'];

            if (! is_array($post) || ($post['id'] ?? null) !== 'payload_app_id') {
                $errors[] = "postValidation.id must be 'payload_app_id'.";
            } else {
                $postConfig = $post['config'] ?? null;

                if (! is_array($postConfig) || ! is_string($postConfig['jsonPath'] ?? null) || trim($postConfig['jsonPath']) === '') {
                    $errors[] = 'postValidation.config.jsonPath is required.';
                }

                $allowedRefs = $postConfig['allowedRefs'] ?? null;

                if (! is_array($allowedRefs) || $allowedRefs === []) {
                    $errors[] = 'postValidation.config.allowedRefs must be a non-empty list.';
                } else {
                    foreach ($allowedRefs as $ref) {
                        if (! in_array($ref, self::allowedRefs(), true)) {
                            $errors[] = "postValidation allowed ref '{$ref}' must be one of: ".implode(', ', self::allowedRefs()).'.';
                        }
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    public static function allowedRefs(): array
    {
        return ['client_secret', 'webhook_secret', 'partner_api_key', 'app_id', 'client_id'];
    }
}
