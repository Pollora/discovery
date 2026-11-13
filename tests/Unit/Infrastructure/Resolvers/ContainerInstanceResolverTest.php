<?php

declare(strict_types=1);

use Pollora\Discovery\Infrastructure\Resolvers\ContainerInstanceResolver;
use Psr\Container\ContainerInterface;

it('asks the PSR-11 container for the class', function (): void {
    $instance = new stdClass;
    $container = Mockery::mock(ContainerInterface::class);
    $container->shouldReceive('get')->once()->with('App\Service')->andReturn($instance);

    expect((new ContainerInstanceResolver($container))->make('App\Service'))->toBe($instance);
});

it('lets the container error through', function (): void {
    $container = Mockery::mock(ContainerInterface::class);
    $container->shouldReceive('get')->andThrow(new RuntimeException('not bound'));

    expect(fn (): object => (new ContainerInstanceResolver($container))->make('App\Service'))
        ->toThrow(RuntimeException::class, 'not bound');
});
