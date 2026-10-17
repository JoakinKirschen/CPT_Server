<?php

declare(strict_types=1);

namespace CptLibrary\Auth;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $canWrite,
    ) {
    }
}
