<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();

            // Denormalised from the deck so the daily review queue can filter by
            // owner without joining, and so a card can outlive its deck.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deck_id')->nullable()->constrained()->nullOnDelete();

            $table->string('front_text', 500);
            $table->text('back_text')->nullable();
            $table->text('example_sentence')->nullable();
            $table->string('pronunciation_note')->nullable();

            // Unsplash gives us a remote URL; image_path holds our cached copy so
            // reviews never depend on their 50 req/hour rate limit. Attribution is
            // required by the Unsplash API terms, so it travels with the card.
            $table->text('image_url')->nullable();
            $table->string('image_path')->nullable();
            $table->json('image_attribution')->nullable();

            // Generated once at creation, never per review.
            $table->string('audio_front_path')->nullable();
            $table->string('audio_back_path')->nullable();

            // The AI fields above are filled by queued jobs, so a card is visible
            // before its content exists and needs to say where it is in that process.
            $table->string('generation_status')->default('pending');
            $table->text('generation_error')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'deck_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
