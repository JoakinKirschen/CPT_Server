<?php

declare(strict_types=1);

namespace CptLibrary\Auth;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $canWrite,
        /** Keys of one group see each other's group uploads (1.2.0). */
        public readonly ?string $group = null,
    ) {
    }
}
