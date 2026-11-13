<?php

declare(strict_types=1);

use Pollora\Discovery\Domain\Contracts\InstanceResolverInterface;
use Pollora\Discovery\Domain\Exceptions\InstantiationFailedException;
use Pollora\Discovery\Infrastructure\Services\ReflectionCache;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class ReflCacheTestMarker {}

#[Attribute(Attribute::TARGET_METHOD)]
final class ReflCacheTestOtherMarker {}

#[ReflCacheTestMarker]
final class ReflCacheTestSubject
{
    #[ReflCacheTestMarker]
    public function marked(): void {}

    #[ReflCacheTestOtherMarker]
    public function otherMarked(): void {}

    public function plain(): void {}

    #[ReflCacheTestMarker]
    private function hidden(): void {}
}

abstract class ReflCacheTestAbstract {}

interface ReflCacheTestInterface {}

trait ReflCacheTestTrait {}

final class ReflCacheTestPrivateConstructor
{
    private function __construct() {}
}

it('gives the reflection of a class, once', function (): void {
    $cache = new ReflectionCache;

    $first = $cache->getClassReflection(ReflCacheTestSubject::class);

    expect($first->getName())->toBe(ReflCacheTestSubject::class)
        ->and($cache->getClassReflection(ReflCacheTestSubject::class))->toBe($first);
});

it('says a class is unknown until it has been reflected', function (): void {
    $cache = new ReflectionCache;

    expect($cache->hasClass(ReflCacheTestSubject::class))->toBeFalse();

    $cache->getClassReflection(ReflCacheTestSubject::class);

    expect($cache->hasClass(ReflCacheTestSubject::class))->toBeTrue();
});

it('names the class it could not reflect, and does not retry it', function (): void {
    $cache = new ReflectionCache;

    try {
        $cache->getClassReflection('App\Missing\Thing');
        $this->fail('A missing class was reflected');
    } catch (ReflectionException $exception) {
        expect($exception->getMessage())->toContain('Failed to create reflection for class App\Missing\Thing')
            ->and($exception->getPrevious())->toBeInstanceOf(ReflectionException::class);
    }

    expect($cache->hasClass('App\Missing\Thing'))->toBeTrue();
});

it('lists the public methods only', function (): void {
    $names = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionCache)->getPublicMethods(ReflCacheTestSubject::class),
    );

    expect($names)->toBe(['marked', 'otherMarked', 'plain']);
});

it('finds the public methods carrying a given attribute', function (): void {
    $cache = new ReflectionCache;

    $marked = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        $cache->getMethodsWithAttributes(ReflCacheTestSubject::class, ReflCacheTestMarker::class),
    );

    expect($marked)->toBe(['marked']);
});

it('finds the public methods carrying any attribute when none is given', function (): void {
    $cache = new ReflectionCache;

    $marked = array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        $cache->getMethodsWithAttributes(ReflCacheTestSubject::class),
    );

    expect($marked)->toBe(['marked', 'otherMarked'])
        ->and($cache->getMethodsWithAttributes(ReflCacheTestSubject::class))->toBe($cache->getMethodsWithAttributes(ReflCacheTestSubject::class));
});

it('reads the attributes of a class, all of them or one kind', function (): void {
    $cache = new ReflectionCache;

    expect($cache->getClassAttributes(ReflCacheTestSubject::class))->toHaveCount(1)
        ->and($cache->getClassAttributes(ReflCacheTestSubject::class, ReflCacheTestMarker::class))->toHaveCount(1)
        ->and($cache->getClassAttributes(ReflCacheTestSubject::class, ReflCacheTestOtherMarker::class))->toBe([]);
});

it('builds an instance through its resolver, and keeps it', function (): void {
    $resolver = Mockery::mock(InstanceResolverInterface::class);
    $resolver->shouldReceive('make')->once()->with(ReflCacheTestSubject::class)->andReturn(new ReflCacheTestSubject);
    $cache = new ReflectionCache($resolver);

    expect($cache->getClassInstance(ReflCacheTestSubject::class))->toBe($cache->getClassInstance(ReflCacheTestSubject::class));
});

it('builds an instance by reflection when it has no resolver', function (): void {
    expect((new ReflectionCache)->getClassInstance(ReflCacheTestSubject::class))->toBeInstanceOf(ReflCacheTestSubject::class);
});

it('refuses to instantiate what cannot be instantiated', function (string $className): void {
    $resolver = Mockery::mock(InstanceResolverInterface::class);
    $resolver->shouldNotReceive('make');

    expect(fn (): object => (new ReflectionCache($resolver))->getClassInstance($className))
        ->toThrow(InstantiationFailedException::class, 'Failed to instantiate class '.$className);
})->with([
    'an abstract class' => ReflCacheTestAbstract::class,
    'an interface' => ReflCacheTestInterface::class,
    'a trait' => ReflCacheTestTrait::class,
    'a class with a private constructor' => ReflCacheTestPrivateConstructor::class,
    'a class that does not exist' => 'App\Missing\Thing',
]);

it('wraps what the resolver throws', function (): void {
    $resolver = Mockery::mock(InstanceResolverInterface::class);
    $resolver->shouldReceive('make')->andThrow(new LogicException('no binding'));

    expect(fn (): object => (new ReflectionCache($resolver))->getClassInstance(ReflCacheTestSubject::class))
        ->toThrow(InstantiationFailedException::class, 'no binding');
});

it('preloads the classes it can reflect, skipping the others and those it already knows', function (): void {
    $cache = new ReflectionCache;

    $cache->preloadClasses([ReflCacheTestSubject::class, 'App\Missing\Thing', ReflCacheTestSubject::class]);

    expect($cache->hasClass(ReflCacheTestSubject::class))->toBeTrue()
        ->and($cache->hasClass('App\Missing\Thing'))->toBeTrue();
});
