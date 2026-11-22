<?php
// config.php — every knob you might want to tweak, in ONE place.
// This is the "settings" file: change a value, reload the page, done.
// The config() function returns the array (cached), so any file can read a value.

function config(?string $key = null)
{
    static $c = null;
    if ($c === null) {
        $c = [
            // ---- The memory board ----
            'pairs' => 6,               // matching pairs => board is pairs*2 cards
            'emojis' => [               // pool of symbols; pairs are picked from here
                '🐶', '🐱', '🦊', '🐸', '🐙', '🦋',
                '🍎', '🍕', '⭐', '🌈', '🎲', '🚀',
            ],

            // ---- Duel pacing ----
            'unflip_delay_ms' => 1500,  // how long a mismatched pair stays revealed
            'poll_ms' => 600,           // how often the watcher refreshes (data -> view)

            // ---- Rooms & chat ----
            'rooms_dir' => __DIR__ . '/rooms', // one JSON file per room
            'lobby_id' => 'lobby',             // the always-on pre/post-game room
            'code_chars' => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', // no confusing 0/O/1/I
            'code_length' => 6,                // game codes like "A7B3X9"
            'max_messages' => 50,              // keep only the last N messages per room
            'max_message_len' => 200,          // hard cap on one chat message
            'max_lobby_people' => 32,          // how many names the lobby remembers

            // ---- Labels ----
            'default_names' => ['Player 1', 'Player 2'],
        ];
    }
    return $key === null ? $c : ($c[$key] ?? null);
}
