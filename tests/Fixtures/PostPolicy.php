<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests\Fixtures;

final class PostPolicy
{
    public function restore(?object $user, Post $post): bool
    {
        return true;
    }
}
