<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests\Fixtures;

enum Status: string
{
    case Pending  = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
