<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Translation extends Model
{
    protected $table = 'translations';

    protected $guarded = [];

    public $timestamps = false;
}
