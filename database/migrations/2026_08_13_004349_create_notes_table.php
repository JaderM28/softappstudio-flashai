<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A note is one sentence you are learning, stored once. The questions asked
     * about it live in `cards`, so editing a typo here fixes every question at
     * once instead of leaving copies to drift apart.
     */
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Required, and deleting a deck takes its sentences with it. Every
            // user gets a default deck, so quick capture never has to stop and
            // ask which deck this belongs in.
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();

            $table->text('sentence');

            // The word or chunk being learned, exactly as it appears in the
            // sentence — this is what the cloze card blanks out.
            $table->string('target');

            // Dictionary form, so adding "borrowed" can warn that "borrow" is
            // already being studied.
            $table->string('target_lemma')->nullable();

            // Both are filled whenever the AI can produce them; which one the
            // review screen shows is the deck's display preference. Asking for
            // both costs nothing extra, and it means changing your mind later
            // never means regenerating.
            $table->text('meaning')->nullable();
            $table->text('translation')->nullable();

            $table->string('pronunciation')->nullable();

            // The search terms describe the scene, not the target word:
            // searching Unsplash for "borrowed" returns handshakes and loan
            // paperwork, while "umbrella rain street" returns the sentence.
            // Kept so a bad picture can be refetched without another AI call.
            $table->string('image_query')->nullable();
            $table->text('image_url')->nullable();
            $table->string('image_path')->nullable();
            $table->json('image_attribution')->nullable();

            // Generated once when the note is created, never per review.
            $table->string('audio_sentence_path')->nullable();
            $table->string('audio_target_path')->nullable();

            // Where the sentence came from — a series, a book, typed by hand.
            $table->string('source')->nullable();

            // Tracked per asset because the three services fail independently
            // and Unsplash rate-limits far sooner than the others. A note with
            // text and audio but no picture is still perfectly studiable.
            $table->string('content_status')->default('pending');
            $table->string('image_status')->default('pending');
            $table->string('audio_status')->default('pending');
            $table->json('generation_errors')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'deck_id']);
            $table->index(['user_id', 'target_lemma']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
