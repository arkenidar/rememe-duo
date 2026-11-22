<?php
// api.php — the whole backend in one file. Everything is a JSON endpoint:
//   api.php?action=...&param=...&param=...
// The game state lives in room files (see store.php). No database, no sessions.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

// ---------- tiny helpers ----------

function param(string $name): ?string
{
    return isset($_GET[$name]) ? trim((string) $_GET[$name]) : null;
}

// Cut a string to $max bytes without splitting a multi-byte character in half.
function clip(string $s, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($s, 0, $max);
    }
    return substr($s, 0, $max); // ASCII-safe fallback
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function respond_error(string $message, int $status = 400): never
{
    respond(['ok' => false, 'error' => $message], $status);
}

// The standard success envelope: the room + who YOU are + (for the lobby) open games.
function respond_room(array $room, ?string $you, ?array $openGames = null): never
{
    $out = ['ok' => true, 'you' => $you, 'room' => $room];
    if ($openGames !== null) {
        $out['openGames'] = $openGames;
    }
    respond($out);
}

// ---------- room factories ----------

function new_lobby(string $id): array
{
    return ['id' => $id, 'kind' => 'lobby', 'people' => [], 'messages' => []];
}

function new_game(string $id, array $p1): array
{
    return [
        'id' => $id, 'kind' => 'game', 'status' => 'waiting',
        'players' => ['p1' => $p1, 'p2' => null],
        'messages' => [], 'cards' => [],
        'turn' => null, 'scores' => ['p1' => 0, 'p2' => 0],
        'first' => null, 'mismatch' => null, 'revertAt' => null, 'winner' => null,
    ];
}

function make_code(): string
{
    $chars = config('code_chars');
    $len   = (int) config('code_length');
    $id    = '';
    for ($i = 0; $i < $len; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    // (astronomically unlikely collision, but avoid it anyway)
    return is_file(room_path($id)) ? make_code() : $id;
}

// ---------- the board ----------

function build_board(): array
{
    $emojis = config('emojis');
    $pairs  = min((int) config('pairs'), count($emojis));
    shuffle($emojis);
    $chosen = array_slice($emojis, 0, $pairs);

    $deck = [];
    foreach ($chosen as $e) {
        $deck[] = $e;
        $deck[] = $e; // two of each
    }
    shuffle($deck);

    $cards = [];
    foreach ($deck as $i => $emoji) {
        $cards[] = ['id' => $i, 'emoji' => $emoji, 'flipped' => false, 'matched' => false];
    }
    return $cards;
}

// ---------- game rules ----------

function matched_count(array $room): int
{
    $n = 0;
    foreach ($room['cards'] as $c) {
        if ($c['matched']) {
            $n++;
        }
    }
    return $n;
}

function winner_of(array $room): string
{
    $s  = $room['scores'];
    $p1 = $s['p1'] ?? 0;
    $p2 = $s['p2'] ?? 0;
    return $p1 === $p2 ? 'tie' : ($p1 > $p2 ? 'p1' : 'p2');
}

// Resolve a pending mismatch once the reveal delay has passed:
// flip the pair back down and hand the turn to the opponent.
function tick(array &$room): void
{
    if (($room['kind'] ?? '') !== 'game' || $room['mismatch'] === null) {
        return;
    }
    if ((int) (microtime(true) * 1000) < $room['revertAt']) {
        return;
    }
    foreach ($room['mismatch'] as $i) {
        $room['cards'][$i]['flipped'] = false;
    }
    $room['mismatch'] = null;
    $room['revertAt'] = null;
    $room['turn'] = $room['turn'] === 'p1' ? 'p2' : 'p1';
}

// $slot is 'p1' or 'p2'. Returns an error string, or null on success.
function apply_flip(array &$room, string $slot, int $idx): ?string
{
    if (($room['status'] ?? '') !== 'playing') return 'game is not active';
    if ($room['turn'] !== $slot)               return 'not your turn';
    if ($idx < 0 || $idx >= count($room['cards'])) return 'bad card';
    if ($room['mismatch'] !== null)            return 'wait for the pair to turn back';

    $card = &$room['cards'][$idx];
    if ($card['flipped'] || $card['matched'])  return 'card already revealed';

    $card['flipped'] = true;

    // first card of the pair?
    if ($room['first'] === null) {
        $room['first'] = $idx;
        return null;
    }

    // second card -> compare
    $a = $room['first'];
    $b = $idx;
    $room['first'] = null;

    if ($room['cards'][$a]['emoji'] === $room['cards'][$b]['emoji']) {
        // match: keep both, score, keep the turn
        $room['cards'][$a]['matched'] = true;
        $room['cards'][$b]['matched'] = true;
        $room['cards'][$a]['flipped'] = false;
        $room['cards'][$b]['flipped'] = false;
        $room['scores'][$slot] = ($room['scores'][$slot] ?? 0) + 1;
        if (matched_count($room) === count($room['cards'])) {
            $room['status'] = 'finished';
            $room['winner'] = winner_of($room);
        }
    } else {
        // miss: reveal briefly, then flip back + pass turn (see tick())
        $room['mismatch'] = [$a, $b];
        $room['revertAt'] = (int) (microtime(true) * 1000) + (int) config('unflip_delay_ms');
    }
    return null;
}

// ---------- membership helpers ----------

function in_room(array $room, string $token): bool
{
    if (($room['kind'] ?? '') === 'lobby') {
        foreach ($room['people'] as $p) {
            if ($p['token'] === $token) return true;
        }
        return false;
    }
    foreach ($room['players'] ?? [] as $pl) {
        if (is_array($pl) && $pl['token'] === $token) return true;
    }
    return false;
}

function name_in_room(array $room, string $token): string
{
    if (($room['kind'] ?? '') === 'lobby') {
        foreach ($room['people'] as $p) {
            if ($p['token'] === $token) return $p['name'];
        }
    }
    foreach ($room['players'] ?? [] as $pl) {
        if (is_array($pl) && $pl['token'] === $token) return $pl['name'];
    }
    return '?';
}

function your_slot(array $room, string $token): ?string
{
    foreach ($room['players'] ?? [] as $slot => $pl) {
        if (is_array($pl) && $pl['token'] === $token) return $slot;
    }
    return null;
}

function add_person(array &$room, string $token, string $name): void
{
    foreach ($room['people'] as &$p) {
        if ($p['token'] === $token) {
            $p['name'] = $name;
            return;
        }
    }
    $room['people'][] = ['token' => $token, 'name' => $name];
    $room['people'] = array_slice($room['people'], -(int) config('max_lobby_people'));
}

// ---------- the router ----------

$action = param('action') ?? '';
$token  = param('token') ?? '';

try {
switch ($action) {
    case 'hello': {
        respond(['ok' => true, 'token' => bin2hex(random_bytes(6))]);
    }

    case 'lobby': {
        if ($token === '') respond_error('missing token');
        $name = param('name') ?: config('default_names')[0];
        $room = room_load(config('lobby_id'));
        if (empty($room['kind'])) $room = new_lobby(config('lobby_id'));
        add_person($room, $token, $name);
        room_save($room);
        respond_room($room, null, open_games());
    }

    case 'create': {
        if ($token === '') respond_error('missing token');
        $name = param('name') ?: config('default_names')[0];
        $room = new_game(make_code(), ['token' => $token, 'name' => $name]);
        room_save($room);
        respond_room($room, 'p1');
    }

    case 'join': {
        if ($token === '') respond_error('missing token');
        $id = strtoupper(param('room') ?? '');
        if ($id === '') respond_error('missing room code');
        $room = room_load($id);
        if (empty($room['kind']) || $room['kind'] !== 'game') respond_error('room not found');
        if (($room['status'] ?? '') !== 'waiting') respond_error('game is not joinable');
        if ($room['players']['p1']['token'] === $token) respond_error('that is your own game');
        $room['players']['p2'] = ['token' => $token, 'name' => param('name') ?: config('default_names')[1]];
        $room['status'] = 'playing';
        $room['cards']  = build_board();
        $room['turn']   = 'p1';
        room_save($room);
        respond_room($room, 'p2');
    }

    case 'chat': {
        if ($token === '') respond_error('missing token');
        $id   = param('room');
        $text = param('text');
        if ($id === null || $text === null) respond_error('missing room or text');
        $room = room_load($id);
        if (empty($room['kind'])) respond_error('room not found');
        if (!in_room($room, $token)) respond_error('you are not in this room');
        $text = clip($text, (int) config('max_message_len'));
        $room['messages'][] = ['name' => name_in_room($room, $token), 'text' => $text, 'at' => time()];
        $room['messages'] = array_slice($room['messages'], -(int) config('max_messages'));
        room_save($room);
        respond_room($room, your_slot($room, $token));
    }

    case 'flip': {
        if ($token === '') respond_error('missing token');
        $id  = param('room');
        $idx = param('card');
        if ($id === null || $idx === null) respond_error('missing room or card');
        $room = room_load($id);
        if (empty($room['kind'])) respond_error('room not found');
        $slot = your_slot($room, $token);
        if ($slot === null) respond_error('you are not a player here');
        tick($room);
        $err = apply_flip($room, $slot, (int) $idx);
        if ($err !== null) respond_error($err, 409);
        room_save($room);
        respond_room($room, $slot);
    }

    case 'state': {
        if ($token === '') respond_error('missing token');
        $id = param('room');
        if ($id === null) respond_error('missing room');
        $room = room_load($id);
        if (empty($room['kind'])) respond_error('room not found');
        tick($room);
        if (($room['kind'] ?? '') === 'game') {
            room_save($room); // persist any tick() change
        }
        $open = ($room['kind'] === 'lobby') ? open_games() : null;
        respond_room($room, your_slot($room, $token), $open);
    }

    default:
        respond_error('unknown action: ' . $action, 404);
}
} catch (Throwable $e) {
    respond_error('server error: ' . $e->getMessage(), 500);
}

