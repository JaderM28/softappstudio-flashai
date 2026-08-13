<?php

namespace Database\Seeders;

use App\Actions\CreateNote;
use App\Actions\EnsureDefaultDeck;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * A local account with a handful of sentences, enough to walk through a
     * review session by hand.
     */
    public function run(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'dev@softappstudio.com'],
            [
                'name' => 'Jader',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $deck = app(EnsureDefaultDeck::class)->handle($user);

        $samples = [
            [
                'sentence' => 'She borrowed my umbrella yesterday.',
                'target' => 'borrowed',
                'meaning' => 'took something to use and give back later',
                'translation' => 'Ayer me pidió prestado el paraguas.',
                'source' => 'manual',
            ],
            [
                'sentence' => 'They called off the meeting at the last minute.',
                'target' => 'called off',
                'meaning' => 'cancelled something that was planned',
                'translation' => 'Cancelaron la reunión a último momento.',
                'source' => 'manual',
            ],
            [
                'sentence' => 'He is always running late for work.',
                'target' => 'running late',
                'meaning' => 'arriving later than planned',
                'translation' => 'Siempre llega tarde al trabajo.',
                'source' => 'manual',
            ],
            [
                'sentence' => 'The bakery on the corner closes early on Sundays.',
                'target' => 'bakery',
                'meaning' => 'a shop that makes and sells bread and cakes',
                'translation' => 'La panadería de la esquina cierra temprano los domingos.',
                'source' => 'manual',
            ],
        ];

        $createNote = app(CreateNote::class);

        foreach ($samples as $sample) {
            if ($user->notes()->where('sentence', $sample['sentence'])->exists()) {
                continue;
            }

            $createNote->handle($user, $deck, $sample);
        }

        $this->command?->info(sprintf(
            'Seeded %s with %d sentences and %d cards. Password: password',
            $user->email,
            $user->notes()->count(),
            $user->cards()->count(),
        ));
    }
}
