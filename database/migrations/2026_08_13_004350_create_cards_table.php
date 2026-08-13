<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One question about a note, with its own schedule.
     *
     * The scheduling state sits on this row rather than in a separate progress
     * table: building a session is the query that runs most, and there is no
     * reason to make it join.
     */
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Denormalised from the note so per-deck daily limits can be
            // applied while building the queue without a join.
            $table->foreignId('deck_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->string('queue')->default('new');

            // Index into the configured learning steps. Null once graduated.
            $table->unsignedTinyInteger('learning_step')->nullable();

            $table->unsignedInteger('repetitions')->default(0);
            $table->decimal('easiness', 4, 2)->default(2.50);

            // Named interval_days rather than `interval`, which is a PostgreSQL
            // type name that needs quoting in raw SQL.
            $table->unsignedInteger('interval_days')->default(0);
            $table->timestamp('next_review_at');

            // Failures after graduating. Drives leech detection: a card missed
            // this often is not being learned, the sentence is bad.
            $table->unsignedInteger('lapses')->default(0);

            // Set when another card of the same note was answered today, so the
            // second question does not follow the first while the answer is
            // still on screen.
            $table->timestamp('buried_until')->nullable();

            $table->unsignedTinyInteger('last_grade')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            // One cloze card and one listening card per note, never two.
            $table->unique(['note_id', 'type']);

            // The query that builds every session.
            $table->index(['user_id', 'queue', 'next_review_at']);
            $table->index(['deck_id', 'queue']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
