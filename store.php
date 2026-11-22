<?php
// store.php — the ONLY place that reads and writes room files.
// A "room" is just a PHP array saved as JSON in rooms/<id>.json.
// The lobby and every game room are the same kind of thing.

require_once __DIR__ . '/config.php';

function rooms_dir(): string
{
    $dir = config('rooms_dir');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('cannot create rooms directory: ' . $dir);
    }
    return $dir;
}

// Turn a room id into a safe file path (no ".." or "/" tricks).
function room_path(string $id): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
    return rooms_dir() . '/' . $safe . '.json';
}

// Load a room; a fresh empty room if it doesn't exist yet.
function room_load(string $id): array
{
    $path = room_path($id);
    if (!is_file($path)) {
        return ['id' => $id, 'kind' => null];
    }
    $json = file_get_contents($path);
    $room = json_decode($json, true);
    return is_array($room) ? $room : ['id' => $id, 'kind' => null];
}

// Save a room: flock() so two devices don't corrupt it, then atomic rename.
function room_save(array $room): void
{
    $path = room_path($room['id']);
    $tmp  = $path . '.tmp';
    $fh   = @fopen($tmp, 'c');
    if ($fh === false) {
        throw new RuntimeException('cannot write room file: ' . $tmp . ' — check that the web server user can write to the rooms directory');
    }
    flock($fh, LOCK_EX);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($room, JSON_UNESCAPED_UNICODE));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    rename($tmp, $path); // atomic on the same filesystem
}

// List game rooms still waiting for a second player (for the lobby screen).
function open_games(): array
{
    $out = [];
    foreach (glob(rooms_dir() . '/*.json') as $file) {
        $room = json_decode((string) file_get_contents($file), true);
        if (is_array($room) && ($room['kind'] ?? '') === 'game' && ($room['status'] ?? '') === 'waiting') {
            $out[] = ['id' => $room['id'], 'p1' => $room['players']['p1']['name'] ?? '?'];
        }
    }
    return $out;
}
