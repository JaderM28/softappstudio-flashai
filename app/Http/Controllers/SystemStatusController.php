<?php

namespace App\Http\Controllers;

use App\Services\Diagnostics\SystemCheck;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The screen that answers "why is this card missing its picture?".
 *
 * It runs the probes synchronously: whoever opened it is standing there
 * watching, and a queued diagnostic would be useless the moment the thing being
 * diagnosed is the queue itself.
 */
class SystemStatusController extends Controller
{
    /** Kept short so the probes are the page, not the essay above them. */
    private const DEFAULT_PHRASE = 'She borrowed my umbrella yesterday.';

    public function __construct(private readonly SystemCheck $check) {}

    public function show(): View
    {
        return view('system.show', [
            'phrase' => self::DEFAULT_PHRASE,
            'language' => 'en',
            'probes' => collect(),
            'environment' => $this->check->environment(),
        ]);
    }

    /**
     * Renders the results itself rather than redirecting to show().
     *
     * Two reasons, and the first one is not stylistic: this app configures
     * session serialization as JSON, which is the safer default but turns any
     * flashed object into a plain array — the probe results would arrive at the
     * view as arrays and the page would break. Second, a POST that renders
     * makes the browser ask before repeating it, and repeating it spends real
     * API quota.
     */
    public function run(Request $request): View
    {
        $validated = $request->validate([
            'phrase' => ['required', 'string', 'max:200'],
            'language' => ['required', 'string', 'max:8'],
            'groups' => ['array'],
            'groups.*' => ['string', 'in:'.implode(',', SystemCheck::GROUPS)],
        ]);

        return view('system.show', [
            'phrase' => $validated['phrase'],
            'language' => $validated['language'],
            'probes' => $this->check->run(
                $validated['phrase'],
                $validated['language'],
                $validated['groups'] ?? SystemCheck::GROUPS,
            ),
            'environment' => $this->check->environment(),
        ]);
    }
}
