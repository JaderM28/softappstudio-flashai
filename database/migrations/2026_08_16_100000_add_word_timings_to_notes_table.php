<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each word falls inside the sentence clip.
     *
     * Written once, when the audio is made, and read on every tap. Nullable
     * because it is an enhancement and not a requirement: a note without
     * timings still lets its words be tapped — the clip is simply synthesised
     * per word instead of sliced out of the sentence.
     */
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->json('word_timings')->nullable()->after('audio_target_path');
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table) {
            $table->dropColumn('word_timings');
        });
    }
};
