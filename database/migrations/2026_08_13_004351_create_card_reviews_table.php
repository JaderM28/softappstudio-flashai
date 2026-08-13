<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An append-only log of every grade the user has given.
     *
     * card_progress only remembers the latest review, which is all SM-2 needs but
     * not enough for CU-05: the streak, the "reviewed today" count and the 7-day
     * activity chart all need history. It also makes it possible to audit why a
     * card ended up with the interval it has.
     */
    public function up(): void
    {
        Schema::create('card_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('quality');

            // Before/after snapshots of the SM-2 state this review produced.
            $table->unsignedInteger('repetitions_before');
            $table->unsignedInteger('repetitions_after');
            $table->decimal('easiness_before', 4, 2);
            $table->decimal('easiness_after', 4, 2);
            $table->unsignedInteger('interval_days_before');
            $table->unsignedInteger('interval_days_after');

            $table->timestamp('reviewed_at');

            // Drives the daily counts, the streak and the 7-day chart.
            $table->index(['user_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_reviews');
    }
};
