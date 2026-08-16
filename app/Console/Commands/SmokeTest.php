<?php

namespace App\Console\Commands;

use App\Services\Diagnostics\SystemCheck;
use App\Support\ProbeResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Calls every external service for real and says what happened.
 *
 * The screen at /system does the same thing for whoever owns the installation.
 * This exists beside it for the two jobs a screen cannot do: running against a
 * deployment from a terminal, and failing with a non-zero exit status so a
 * pipeline can refuse to continue.
 */
class SmokeTest extends Command
{
    protected $signature = 'flashai:smoke
        {phrase=She borrowed my umbrella yesterday. : text to search a picture for and read aloud}
        {--language=en : BCP-47 tag the audio is spoken in}
        {--only= : comma-separated groups to run — image, audio, sentence, infra}
        {--keep : leave the generated files in place instead of sweeping them}';

    protected $description = 'Call the image, speech and sentence services for real and report what came back';

    public function handle(SystemCheck $check): int
    {
        $phrase = (string) $this->argument('phrase');

        $groups = $this->option('only')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))))
            : SystemCheck::GROUPS;

        $unknown = array_diff($groups, SystemCheck::GROUPS);

        if ($unknown !== []) {
            $this->components->error('Unknown group: '.implode(', ', $unknown));

            return self::INVALID;
        }

        $this->components->info("Probing with: \"{$phrase}\"");

        $results = $check->run($phrase, (string) $this->option('language'), $groups);

        $this->table(
            ['Service', 'Group', 'Status', 'ms', 'Detail'],
            $results->map(fn (ProbeResult $r) => [
                $r->service,
                $r->group,
                $this->badge($r),
                $r->durationMs ?: '—',
                mb_strimwidth(str_replace("\n", ' ', $r->detail), 0, 78, '…'),
            ])->all(),
        );

        $this->reportArtefacts($results);

        $this->newLine();

        foreach ($check->environment() as $label => $value) {
            $this->line(sprintf('  <fg=gray>%-20s</> %s', $label, $value));
        }

        $problems = $results->filter(fn (ProbeResult $r) => $r->isProblem());

        $this->newLine();

        if ($problems->isEmpty()) {
            $this->components->info('Everything answered.');

            return self::SUCCESS;
        }

        // The whole message, not a summary: this is the line someone reads at
        // three in the morning wondering why no cards have pictures.
        foreach ($problems as $problem) {
            $this->components->error("{$problem->service}: {$problem->detail}");
        }

        return self::FAILURE;
    }

    private function badge(ProbeResult $result): string
    {
        return match (true) {
            $result->status->isProblem() => '<fg=red>FAILED</>',
            $result->status->value === 'skipped' => '<fg=yellow>skipped</>',
            default => '<fg=green>ok</>',
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ProbeResult>  $results
     */
    private function reportArtefacts($results): void
    {
        $files = $results
            ->filter(fn (ProbeResult $r) => filled($r->payload['path'] ?? null))
            ->map(fn (ProbeResult $r) => $r->payload['path']);

        if ($files->isEmpty()) {
            return;
        }

        $this->newLine();

        if (! $this->option('keep')) {
            $disk = Storage::disk(config('flashai.media.disk'));

            foreach ($files as $path) {
                $disk->delete($path);
            }

            $this->line('  <fg=gray>'.$files->count().' file(s) written and removed again.</>');
            $this->line('  <fg=gray>Pass --keep to open them and confirm the picture and the audio are real.</>');

            return;
        }

        $this->line('  <fg=gray>Written, and kept — open these to check them yourself:</>');

        foreach ($files as $path) {
            $this->line("    {$path}");
        }
    }
}
