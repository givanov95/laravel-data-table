<?php

declare(strict_types=1);

namespace Givanov95\DataTable\Support;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Redirects back with a success message. Apps without Inertia read it from the session
 * (`session('success')`). Inertia apps show toasts from Inertia flash data (`page.flash.toast`,
 * the `flash` event), so when Inertia is installed the message is flashed there too and the app
 * needs no bridge of its own.
 *
 * @internal
 */
final class SuccessRedirect
{
    public static function back(string $message): RedirectResponse
    {
        if (class_exists(Inertia::class)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
        }

        return back()->with('success', $message);
    }
}
