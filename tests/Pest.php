<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The package has no framework to boot: tests run on Pest's own test case.
| Mockery's expectations are verified after each of them, and count as
| assertions, as they do in Laravel's test case.
|
*/

pest()->afterEach(function (): void {
    if ($container = Mockery::getContainer()) {
        $this->addToAssertionCount($container->mockery_getExpectationCount());
    }

    Mockery::close();
})->in('Unit', 'Benchmark');
