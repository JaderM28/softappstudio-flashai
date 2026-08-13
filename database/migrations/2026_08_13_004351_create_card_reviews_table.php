<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An append-only log of every grade given.
     *
     * The card row only remembers where it is now, which is all the scheduler
     * needs but not enough for the stats screen: the streak, the daily counts
     * and the activity chart all need history. Recording the state on both
     * sides of the review makes a surprising interval auditable, makes undo a
     * matter of restoring one row, and is exactly the history FSRS would need
     * to train on if it replaces SM-2 later.
     */
    public function up(): void
    {
        Schema::create('card_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('grade');

            $table->string('queue_before');
            $table->string('queue_after');
            $table->unsignedTinyInteger('learning_step_before')->nullable();
            $table->unsignedTinyInteger('learning_step_after')->nullable();
            $table->unsignedInteger('repetitions_before');
            $table->unsignedInteger('repetitions_after');
            $table->decimal('easiness_before', 4, 2);
            $table->decimal('easiness_after', 4, 2);
            $table->unsignedInteger('interval_days_before');
            $table->unsignedInteger('interval_days_after');
            $table->unsignedInteger('lapses_after');

            // How long the answer took. Useful for spotting sentences that are
            // technically passing but costing far too much thought.
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('reviewed_at');

            // Drives the daily counts, the streak and the activity chart.
            $table->index(['user_id', 'reviewed_at']);
            $table->index(['card_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_reviews');
    }
};
