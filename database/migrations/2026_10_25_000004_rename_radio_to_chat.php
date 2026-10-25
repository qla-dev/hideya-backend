<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// "Radio" is called Chat everywhere now: the messages table and the event keys
// stored on its rows follow.
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('radio_messages', 'chat_messages');
        $this->renameKeys('radio.', 'chat.');
    }

    public function down(): void
    {
        $this->renameKeys('chat.', 'radio.');
        Schema::rename('chat_messages', 'radio_messages');
    }

    /** Portable (MySQL and the tests' SQLite): one small update per stored event key. */
    private function renameKeys(string $from, string $to): void
    {
        DB::table('chat_messages')->where('key', 'like', $from.'%')->select('key')->distinct()->pluck('key')
            ->each(fn (string $key) => DB::table('chat_messages')->where('key', $key)->update(['key' => $to.substr($key, strlen($from))]));
    }
};
