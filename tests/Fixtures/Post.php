<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Post extends Model
{
    use SoftDeletes;

    protected $table = 'posts';

    protected $guarded = [];

    public $timestamps = false;

    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class);
    }

    public function translations(): MorphMany
    {
        return $this->morphMany(Translation::class, 'translatable');
    }
}
