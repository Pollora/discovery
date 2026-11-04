<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Contracts\InstancePoolInterface;
use Pollora\Discovery\Domain\Services\HasInstancePool;

final class PoolUserLeaf {}

final class PoolUser
{
    use HasInstancePool;

    public function build(string $className, ?callable $fallback = null): object
    {
        return $this->getInstanceFromPool($className, $fallback);
    }

    public function pooled(): bool
    {
        return $this->hasInstancePool();
    }
}

it('asks the pool it was given, whatever implements the contract', function (): void {
    $leaf = new PoolUserLeaf;
    $pool = new class($leaf) implements InstancePoolInterface
    {
        public function __construct(private readonly object $leaf) {}

        public function getInstance(string $className): object
        {
            return $this->leaf;
        }
    };
    $user = new PoolUser;
    $user->setInstancePool($pool);

    expect($user->pooled())->toBeTrue()
        ->and($user->build(PoolUserLeaf::class))->toBe($leaf);
});

it('builds the class by reflection when it has no pool and no fallback', function (): void {
    expect((new PoolUser)->build(PoolUserLeaf::class))->toBeInstanceOf(PoolUserLeaf::class);
});

it('prefers the fallback to building the class itself', function (): void {
    $made = new PoolUserLeaf;

    expect((new PoolUser)->build(PoolUserLeaf::class, fn (): object => $made))->toBe($made);
});
