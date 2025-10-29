<?php

declare(strict_types=1);

namespace JoshCirre\Duo\Attributes;

use Attribute;

/**
 * Attribute to mark models for Duo IndexedDB syncing.
 *
 * Can be used as an alternative to the Syncable trait.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class UseDuo
{
    public function __construct(
        public readonly ?string $syncStrategy = null,
        public readonly ?int $cacheTtl = null,
        public readonly bool $autoSync = true,
    ) {}
}
