<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Services;

use Pollora\Discovery\Domain\Contracts\DiscoveryInterface;
use Pollora\Discovery\Domain\Contracts\DiscoveryItemsInterface;
use Pollora\Discovery\Domain\Models\DiscoveryItems;

/**
 * Is Discovery Trait
 *
 * Provides the default implementation for Discovery interface methods.
 * This trait handles the basic discovery items management functionality
 * that is common to all discovery implementations.
 *
 * Classes using this trait must implement the DiscoveryInterface.
 *
 *
 * @phpstan-require-implements DiscoveryInterface
 */
trait IsDiscovery
{
    /**
     * The discovery items collection
     */
    private DiscoveryItemsInterface $discoveryItems;

    /**
     * {@inheritDoc}
     */
    public function getItems(): DiscoveryItemsInterface
    {
        if (! isset($this->discoveryItems)) {
            $this->discoveryItems = new DiscoveryItems;
        }

        return $this->discoveryItems;
    }

    /**
     * {@inheritDoc}
     */
    public function setItems(DiscoveryItemsInterface $items): void
    {
        $this->discoveryItems = $items;
    }

    /**
     * {@inheritDoc}
     */
    public function getIdentifier(): string
    {
        return static::class;
    }
}
