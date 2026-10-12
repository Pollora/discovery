<?php

declare(strict_types=1);

namespace Pollora\Discovery\Domain\Contracts;

/**
 * Discovery Registrar Interface
 *
 * Finds the discovery classes an environment provides and adds them to the
 * engine before each discovery run. Without a registrar, the engine runs only
 * the discoveries added to it explicitly.
 */
interface DiscoveryRegistrarInterface
{
    /**
     * Add every discovery the engine does not have yet.
     *
     * @param  DiscoveryEngineInterface  $engine  The engine to register discoveries with
     * @return array<int, string> The identifiers of the discoveries added
     */
    public function registerInto(DiscoveryEngineInterface $engine): array;
}
