<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_progress', function (Blueprint $table) {
            $table->id();

            // One row per card: this is the card's current SM-2 state, not history.
            $table->foreignId('card_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('repetitions')->default(0);
            $table->decimal('easiness', 4, 2)->default(2.50);

            // Named interval_days rather than the plan's `interval` because
            // `interval` is a PostgreSQL type name and needs quoting in raw SQL.
            $table->unsignedInteger('interval_days')->default(0);

            $table->timestamp('next_review_at');
            $table->unsignedTinyInteger('last_quality')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            // The hot path: "which cards are due for this user right now".
            $table->index(['user_id', 'next_review_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_progress');
    }
};
