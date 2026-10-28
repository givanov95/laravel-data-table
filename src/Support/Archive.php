<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;

/**
 * Whether a table may show its soft-deleted rows. The archive is opt-in
 * (`allowTrashed()`), needs a model with SoftDeletes (onlyTrashed() and
 * withTrashed() do not exist otherwise) and, when an ability is given, a user
 * the gate lets see it.
 *
 * @internal
 */
final class Archive
{
    public static function supports(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    public static function available(bool $allowed, Model $model, ?string $ability): bool
    {
        return $allowed
            && self::supports($model)
            && ($ability === null || Gate::allows($ability, $model::class));
    }
}
