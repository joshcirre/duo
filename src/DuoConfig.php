<?php

declare(strict_types=1);

namespace JoshCirre\Duo;

/**
 * Type-safe configuration for Duo components.
 *
 * Provides IDE autocomplete and type checking for component-level configuration.
 *
 * @example
 * ```php
 * protected function duoConfig(): DuoConfig|array
 * {
 *     return DuoConfig::make()
 *         ->syncInterval(3000)
 *         ->timestampRefreshInterval(5000)
 *         ->debug(true);
 * }
 * ```
 */
class DuoConfig
{
    public function __construct(
        public readonly ?int $syncInterval = null,
        public readonly ?int $timestampRefreshInterval = null,
        public readonly ?int $maxRetryAttempts = null,
        public readonly ?bool $debug = null,
    ) {
        // Validate ranges
        if ($this->syncInterval !== null && $this->syncInterval < 0) {
            throw new \InvalidArgumentException('syncInterval must be a positive integer');
        }

        if ($this->timestampRefreshInterval !== null && $this->timestampRefreshInterval < 0) {
            throw new \InvalidArgumentException('timestampRefreshInterval must be a positive integer');
        }

        if ($this->maxRetryAttempts !== null && $this->maxRetryAttempts < 0) {
            throw new \InvalidArgumentException('maxRetryAttempts must be a positive integer');
        }
    }

    /**
     * Create a new config instance using named arguments.
     *
     * @example
     * ```php
     * DuoConfig::make(
     *     syncInterval: 3000,
     *     debug: true
     * )
     * ```
     */
    public static function make(
        ?int $syncInterval = null,
        ?int $timestampRefreshInterval = null,
        ?int $maxRetryAttempts = null,
        ?bool $debug = null,
    ): self {
        return new self(
            syncInterval: $syncInterval,
            timestampRefreshInterval: $timestampRefreshInterval,
            maxRetryAttempts: $maxRetryAttempts,
            debug: $debug,
        );
    }

    /**
     * Convert config to array format for JavaScript injection.
     *
     * Only includes non-null values, allowing proper merging with defaults.
     */
    public function toArray(): array
    {
        $config = [];

        if ($this->syncInterval !== null) {
            $config['syncInterval'] = $this->syncInterval;
        }

        if ($this->timestampRefreshInterval !== null) {
            $config['timestampRefreshInterval'] = $this->timestampRefreshInterval;
        }

        if ($this->maxRetryAttempts !== null) {
            $config['maxRetryAttempts'] = $this->maxRetryAttempts;
        }

        if ($this->debug !== null) {
            $config['debug'] = $this->debug;
        }

        return $config;
    }
}
