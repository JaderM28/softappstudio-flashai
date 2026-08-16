<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

abstract class TestCase extends BaseTestCase
{
    /**
     * Nothing in the suite is allowed to reach the network or spawn a process.
     *
     * This is not a tidiness rule. Without it the suite quietly called the real
     * Gemini API with the key from .env — QUEUE_CONNECTION is sync, so posting
     * to notes.store ran the media jobs in-process — and burned the free tier
     * until it answered 429. Those 429s are still in storage/logs/laravel.log,
     * logged under the testing channel, which is how the leak was found.
     *
     * A test that needs an external answer fakes it explicitly. One that
     * forgets now fails loudly here instead of spending real quota.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Process::preventStrayProcesses();
    }
}
