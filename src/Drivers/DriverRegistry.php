<?php

namespace Omarsenusi\MarketplacePlatforms\Drivers;

use InvalidArgumentException;

class DriverRegistry
{
    /**
     * @var array<string, PlatformDriver>
     */
    private array $drivers = [];

    public function register(PlatformDriver $driver): void
    {
        $this->drivers[$driver->key()] = $driver;
    }

    public function get(string $key): ?PlatformDriver
    {
        return $this->drivers[$key] ?? null;
    }

    public function resolve(string $key): PlatformDriver
    {
        $driver = $this->get($key);

        if ($driver === null) {
            throw new InvalidArgumentException("Unknown marketplace platform driver [{$key}].");
        }

        return $driver;
    }

    /**
     * @return list<PlatformDriver>
     */
    public function all(): array
    {
        return array_values($this->drivers);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->drivers);
    }
}
