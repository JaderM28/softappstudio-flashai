<?php

namespace Tests\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Recorded answers from the real services.
 *
 * Every Http::fake() in this suite used to be hand-written from the same
 * documentation the client was written from, which meant a test could only ever
 * confirm that the client agreed with its author. It did agree, and the audio
 * still never worked: the live API returns the clip somewhere the docs do not
 * mention, and no test could see that.
 *
 * These files are what the services actually sent back. See README.md beside
 * them for when they were captured and the one edit made to them.
 */
final class Fixture
{
    /**
     * @return array<string, mixed>
     */
    public static function json(string $name): array
    {
        $path = __DIR__.'/Http/'.$name.'.json';

        if (! is_file($path)) {
            throw new RuntimeException("No recorded response called {$name}. See tests/Fixtures/Http.");
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A stub for Http::fake(). Http::response() hands back a promise, not a
     * Response, which is what the fake handler expects.
     */
    public static function response(string $name, int $status = 200): PromiseInterface
    {
        return Http::response(self::json($name), $status);
    }

    /**
     * The same answer with one value changed, for the cases a test needs to
     * bend — an empty result set, a missing URL — without losing the shape.
     *
     * @param  array<string, mixed>  $overrides  dot-notation paths
     * @return array<string, mixed>
     */
    public static function jsonWith(string $name, array $overrides): array
    {
        $payload = self::json($name);

        foreach ($overrides as $path => $value) {
            data_set($payload, $path, $value);
        }

        return $payload;
    }
}
