<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a sentence must have its picture and its clip before it becomes
     * a card.
     *
     * On by default, because that is the deck this app exists to make: the
     * image carries the meaning and the audio carries the sound, and a card
     * with neither is the word list the whole design argues against. It is a
     * setting rather than a rule so a deck can still be studied as plain text
     * when someone knowingly wants that.
     */
    public function up(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->boolean('require_media')->default(true)->after('show_translation');
        });
    }

    public function down(): void
    {
        Schema::table('decks', function (Blueprint $table) {
            $table->dropColumn('require_media');
        });
    }
};
