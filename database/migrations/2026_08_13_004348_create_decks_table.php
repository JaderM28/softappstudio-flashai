<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();

            $table->string('target_language', 8)->default('en');
            $table->string('native_language', 8)->default('es');

            // Free-form guidance folded into the generation prompt, e.g.
            // "medical vocabulary, formal register" or "B2 level, no slang".
            // Kept open rather than a fixed set of modes so narrowing the
            // output is the prompt's job and never needs a schema change.
            $table->text('prompt_instructions')->nullable();

            // Which questions to generate for each sentence in this deck.
            // The default lives on the model, not here, so it stays tied to
            // CardType rather than being frozen into the schema.
            $table->json('card_types');

            // A display preference, not a generation one: notes always carry
            // both a target-language meaning and a translation when the AI can
            // produce them, so this flips freely without regenerating anything.
            $table->boolean('show_translation')->default(true);

            // Capped per sentence rather than per card, because sentences are
            // the unit the user thinks in. Null falls back to the config value.
            $table->unsignedSmallInteger('new_per_day')->nullable();
            $table->unsignedSmallInteger('reviews_per_day')->nullable();

            // Marks the deck new sentences land in when none is chosen.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            // Deck names are how the user tells them apart, so keep them unique
            // per owner rather than globally.
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decks');
    }
};
