<?php

namespace Omarsenusi\MarketplacePlatforms\Commerce;

use InvalidArgumentException;

/**
 * One route condition for POST /shipping/routes {conditions:[]}.
 *
 * Types and operators follow https://docs.salla.dev/shipping-routes/create.md:
 * types cart_total|cart_weight|cart_quantity|categories|areas|customer_groups|
 * schedule|branches|branch_count|specific_products_quantity|excluded and
 * operators ==|!=|>|>=|<|<=|between|in|not_in|within_range.
 */
final readonly class RouteCondition
{
    /**
     * @param  string|int|float|bool|BetweenRange|ScheduleWindow|list<AreaRule>|list<int>|list<string>  $value
     */
    private function __construct(
        public string $type,
        public string $operator,
        public string|int|float|bool|BetweenRange|ScheduleWindow|array $value,
    ) {}

    public static function scalar(string $type, string $operator, string|int|float|bool $value): self
    {
        return new self($type, $operator, $value);
    }

    public static function between(string $type, string|int|float $min, string|int|float $max): self
    {
        return new self($type, 'between', new BetweenRange($min, $max));
    }

    /**
     * @param  list<int>|list<string>  $ids
     */
    public static function idList(string $type, string $operator, array $ids): self
    {
        if ($operator !== 'in' && $operator !== 'not_in') {
            throw new InvalidArgumentException('Id-list conditions require the in or not_in operator.');
        }

        foreach ($ids as $id) {
            if (! is_int($id) && ! is_string($id)) {
                throw new InvalidArgumentException('Id-list conditions carry integer or string ids only.');
            }
        }

        return new self($type, $operator, array_values($ids));
    }

    /**
     * @param  list<AreaRule>  $rules
     */
    public static function areas(array $rules): self
    {
        foreach ($rules as $rule) {
            if (! $rule instanceof AreaRule) {
                throw new InvalidArgumentException('Areas conditions carry AreaRule DTOs only.');
            }
        }

        return new self('areas', 'in', array_values($rules));
    }

    public static function schedule(ScheduleWindow $window): self
    {
        return new self('schedule', 'within_range', $window);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSallaArray(): array
    {
        $value = $this->value;

        if ($value instanceof BetweenRange || $value instanceof ScheduleWindow) {
            $value = $value->toSallaArray();
        } elseif (is_array($value)) {
            $value = array_map(
                static fn ($v): mixed => $v instanceof AreaRule ? $v->toSallaArray() : $v,
                array_values($value),
            );
        }

        return ['type' => $this->type, 'operator' => $this->operator, 'value' => $value];
    }
}
