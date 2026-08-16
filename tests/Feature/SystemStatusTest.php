<?php

namespace Tests\Feature;

use App\Contracts\SentenceGenerator;
use App\Contracts\SpeechSynthesizer;
use App\Enums\ProbeStatus;
use App\Services\Diagnostics\SystemCheck;
use App\Services\Generation\FakeSentenceGenerator;
use App\Services\Media\FakeSpeechSynthesizer;
use App\Support\ProbeResult;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\Fixture;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('flashai.media.disk'));
        Process::fake();

        config([
            'services.pixabay.key' => 'test-key',
            'services.unsplash.key' => 'test-key',
            'services.gemini.key' => 'test-key',
            'services.cloudflare.account_id' => 'test-account',
            'services.cloudflare.token' => 'test-token',
        ]);

        $this->app->instance(SpeechSynthesizer::class, new FakeSpeechSynthesizer);
        $this->app->instance(SentenceGenerator::class, new FakeSentenceGenerator);
    }

    /**
     * Stubs are matched in the order they are registered and the first hit
     * wins, so this cannot live in setUp: a test that wants one provider broken
     * would find the healthy stub already in front of its own.
     */
    private function fakeHealthyServices(): void
    {
        Http::fake([
            'pixabay.com/*' => Http::response(['hits' => [[
                'webformatURL' => 'https://pixabay.example/a.jpg',
                'user' => 'Ada',
                'user_id' => 7,
                'tags' => 'umbrella, rain',
            ]]]),
            'api.openverse.org/*' => Http::response(['results' => [[
                'thumbnail' => 'https://openverse.example/b.jpg',
                'url' => 'https://openverse.example/b-full.jpg',
                'creator' => 'Bo',
                'title' => 'Umbrellas',
                'license' => 'by-sa',
            ]]]),
            'api.unsplash.com/*' => Http::response(['results' => [[
                'urls' => ['regular' => 'https://unsplash.example/c.jpg'],
                'user' => ['name' => 'Cy', 'links' => ['html' => 'https://unsplash.example/cy']],
                'links' => ['download_location' => 'https://api.unsplash.com/photos/c/download'],
            ]]]),
            // The two voices are probed on their own, so each needs its own
            // answer — a shared stub would hide exactly what this page exists
            // to reveal.
            'api.cloudflare.com/*' => Http::response('mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
            'generativelanguage.googleapis.com/*' => Fixture::response('gemini-tts-interaction'),
            '*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('system.show'))->assertRedirect(route('login'));
    }

    public function test_the_screen_opens_in_local_development(): void
    {
        $this->fakeHealthyServices();

        $this->actingAs(User::factory()->create())
            ->get(route('system.show'))
            ->assertOk()
            ->assertSee('System status');
    }

    /**
     * The page spends real API quota on a button press, and anyone can sign up.
     */
    public function test_outside_local_only_the_owner_may_look(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('system.show'))->assertForbidden();
        $this->actingAs($owner)->get(route('system.show'))->assertOk();
    }

    public function test_an_allow_list_overrides_the_first_account(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $owner = User::factory()->create();
        $named = User::factory()->create(['email' => 'boss@example.com']);

        config(['flashai.diagnostics.emails' => 'boss@example.com']);

        $this->actingAs($named)->get(route('system.show'))->assertOk();
        $this->actingAs($owner)->get(route('system.show'))->assertForbidden();
    }

    public function test_running_the_checks_reports_every_service(): void
    {
        $this->fakeHealthyServices();

        $response = $this->actingAs(User::factory()->create())
            ->post(route('system.run'), [
                'phrase' => 'umbrella rain street',
                'language' => 'en',
                'groups' => SystemCheck::GROUPS,
            ])
            ->assertOk();

        $probes = collect($response->viewData('probes'));

        $this->assertEqualsCanonicalizing(
            ['Pixabay', 'Openverse', 'Unsplash', 'Image download',
                'Cloudflare speech', 'Gemini speech', 'Speech (as configured)',
                'Gemini sentences', 'Storage round trip', 'ffmpeg', 'Queue worker'],
            $probes->pluck('service')->all(),
        );

        $this->assertTrue(
            $probes->every(fn (ProbeResult $p) => ! $p->isProblem()),
            'Every probe should have passed: '.$probes->filter->isProblem()->pluck('detail')->implode(' | '),
        );
    }

    /**
     * The results have to survive all the way onto the page.
     *
     * They did not, the first time: session serialization is configured as JSON
     * here, so redirecting with the probes flashed turned every one of them
     * into a plain array and the view died on the type hint. Asserting on the
     * rendered HTML is what catches that; asserting on the returned collection
     * would have passed.
     */
    public function test_the_results_are_rendered_with_their_detail_and_media(): void
    {
        $this->fakeHealthyServices();

        $this->actingAs(User::factory()->create())
            ->post(route('system.run'), [
                'phrase' => 'umbrella rain street',
                'language' => 'en',
                'groups' => ['image', 'audio'],
            ])
            ->assertOk()
            ->assertSee('Everything answered.')
            ->assertSee('Pixabay')
            ->assertSee('Openverse')
            // The picture and the clip are on the page to be looked at and
            // played, which is the entire reason this screen exists.
            ->assertSee('<audio controls', false)
            ->assertSee('<img src', false);
    }

    public function test_a_failure_shows_the_whole_technical_message(): void
    {
        Http::fake(['*' => Http::response('the bucket said no', 503)]);

        $this->actingAs(User::factory()->create())
            ->post(route('system.run'), [
                'phrase' => 'x',
                'language' => 'en',
                'groups' => ['image'],
            ])
            ->assertOk()
            ->assertSee('checks failed', false)
            // Not a friendly summary. The raw body is the part that identifies
            // which of three providers is unhappy and why.
            ->assertSee('the bucket said no');
    }

    /**
     * Each provider has to be probed on its own. Through the fallback chain a
     * working Pixabay hides a broken Unsplash key, which is the exact question
     * this screen is here to answer.
     */
    public function test_one_broken_provider_does_not_hide_behind_the_others(): void
    {
        Http::fake([
            'api.unsplash.com/*' => Http::response('nope', 401),
            'pixabay.com/*' => Http::response(['hits' => [[
                'webformatURL' => 'https://pixabay.example/a.jpg', 'user' => 'Ada', 'user_id' => 7, 'tags' => 'x',
            ]]]),
            'api.openverse.org/*' => Http::response(['results' => []]),
            '*' => Http::response('bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $probes = collect(app(SystemCheck::class)->run('umbrella', 'en', ['image']))
            ->keyBy('service');

        $this->assertSame(ProbeStatus::Ok, $probes['Pixabay']->status);
        $this->assertSame(ProbeStatus::Failed, $probes['Unsplash']->status);
        $this->assertStringContainsString('HTTP 401', $probes['Unsplash']->detail);

        // Still downloadable, because Pixabay answered. A chain that works is
        // not the same as a chain with nothing wrong with it.
        $this->assertSame(ProbeStatus::Ok, $probes['Image download']->status);
    }

    /**
     * A provider with no key is not a provider that is broken — Unsplash is the
     * last of three and Openverse needs none at all.
     */
    public function test_a_provider_without_a_key_is_skipped_not_failed(): void
    {
        $this->fakeHealthyServices();

        config(['services.pixabay.key' => null]);

        $probes = collect(app(SystemCheck::class)->run('umbrella', 'en', ['image']))->keyBy('service');

        $this->assertSame(ProbeStatus::Skipped, $probes['Pixabay']->status);
        $this->assertFalse($probes['Pixabay']->isProblem());
    }

    /**
     * Storing a file is only half of it. In production the bucket and the
     * public URL are different hosts, and a wrong AWS_URL stores everything
     * perfectly while serving nothing.
     */
    public function test_the_storage_probe_fails_when_the_file_cannot_be_served(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        $probe = collect(app(SystemCheck::class)->run('x', 'en', ['infra']))
            ->firstWhere('service', 'Storage round trip');

        $this->assertSame(ProbeStatus::Failed, $probe->status);
        $this->assertStringContainsString('AWS_URL', $probe->detail);
    }

    public function test_probing_never_throws_even_when_everything_is_down(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);

        $probes = app(SystemCheck::class)->run('x', 'en');

        $this->assertGreaterThan(0, $probes->count());
        $this->assertTrue($probes->contains(fn (ProbeResult $p) => $p->isProblem()));
    }

    public function test_keys_are_never_printed_in_full(): void
    {
        config(['services.gemini.key' => 'super-secret-value-1234']);

        $environment = app(SystemCheck::class)->environment();

        $this->assertSame('set, ending 1234', $environment['Gemini key']);
        $this->assertStringNotContainsString('super-secret', implode(' ', $environment));
    }
}
