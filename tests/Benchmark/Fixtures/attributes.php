<?php

declare(strict_types=1);

/**
 * Stand-ins for the framework's attributes, with the same parameters, so the
 * generated fixtures cost what real classes cost: each attribute a discovery
 * reads is instantiated.
 */

namespace Pollora\Discovery\Tests\Benchmark\Fixtures\Attributes {
    use Attribute;

    #[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
    final class Action
    {
        public function __construct(public string $hook, public int $priority = 10) {}
    }

    #[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
    final class Filter
    {
        public function __construct(public string $hook, public int $priority = 10) {}
    }

    #[Attribute(Attribute::TARGET_METHOD)]
    final class Schedule
    {
        public function __construct(public string $recurrence, public ?string $hook = null) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class PostType
    {
        public function __construct(public ?string $slug = null, public ?string $singular = null, public ?string $plural = null) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class WpRestRoute
    {
        public function __construct(public string $namespace, public string $route) {}
    }
}

namespace Pollora\Discovery\Tests\Benchmark\Fixtures\Attributes\PostType {
    use Attribute;

    #[Attribute(Attribute::TARGET_CLASS)]
    final class Supports
    {
        /** @param  array<int, string>  $features */
        public function __construct(public array $features = []) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class CanExport
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class DeleteWithUser
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class ExcludeFromSearch
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class HasArchive
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class Hierarchical
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class MenuIcon
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class MenuPosition
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class PublicPostType
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class PubliclyQueryable
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class ShowInRest
    {
        public function __construct(public mixed $value = true) {}
    }

    #[Attribute(Attribute::TARGET_CLASS)]
    final class ShowUI
    {
        public function __construct(public mixed $value = true) {}
    }
}

namespace Pollora\Discovery\Tests\Benchmark\Fixtures\Attributes\WpRestRoute {
    use Attribute;

    #[Attribute(Attribute::TARGET_METHOD)]
    final class Method
    {
        public function __construct(public string $methods) {}
    }
}
