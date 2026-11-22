<?php
// index.php — the whole frontend shell.
// Alpine.js draws the view; script.js fetches data and assigns to `state`.
// (For offline play, download the Alpine CDN file next to this as alpine.min.js
//  and change the <script src> below to "alpine.min.js".)
require_once __DIR__ . '/config.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rememe Duo — memory duel</title>
  <link rel="stylesheet" href="style.css">
  <script>
    // server config exposed to the client (single source of truth)
    window.CONFIG = {
      lobby: <?= json_encode(config('lobby_id')) ?>,
      pollMs: <?= (int) config('poll_ms') ?>
    };
  </script>
  <script src="script.js"></script>
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body>
<div x-data="memoryGame()" x-init="boot()" class="wrap">

  <!-- 1. WHO ARE YOU? -->
  <section x-show="view === 'name'" x-cloak class="panel center">
    <h1>Rememe Duo</h1>
    <p class="muted">Two screens, one memory duel. Pick a name to enter the lobby.</p>
    <form @submit.prevent="enterName()" class="row">
      <input x-model="name" maxlength="20" placeholder="your name" autofocus>
      <button>enter lobby</button>
    </form>
  </section>

  <!-- 2. THE LOBBY (pre-game / post-game) -->
  <section x-show="view === 'lobby'" x-cloak class="panel">
    <h1>Lobby</h1>

    <p class="label">who's here</p>
    <div class="chips">
      <template x-for="p in state.people" :key="p.token">
        <span class="chip" x-text="p.name"></span>
      </template>
    </div>

    <p class="label">open games (click to join)</p>
    <div class="chips">
      <template x-for="g in state.openGames" :key="g.id">
        <button class="chip game" @click="joinGame(g.id)">
          <b x-text="g.id"></b> · <span x-text="g.p1"></span>
        </button>
      </template>
      <span x-show="!state.openGames.length" class="muted">none yet — create one below</span>
    </div>

    <div class="row actions">
      <button @click="createGame()">⚔️ new game</button>
      <form @submit.prevent="joinGame()" class="row">
        <input x-model="joinCode" placeholder="game code" maxlength="8" style="text-transform:uppercase">
        <button>join</button>
      </form>
    </div>

    <div class="chat">
      <div class="messages" x-ref="lobbyLog">
        <template x-for="(m, i) in state.messages" :key="i">
          <div class="msg"><b x-text="m.name + ':'"></b><span x-text="m.text"></span></div>
        </template>
      </div>
      <form @submit.prevent="sendChat()" class="row">
        <input x-model="draft" :placeholder="chatPlaceholder" maxlength="200">
        <button>send</button>
      </form>
    </div>
  </section>

  <!-- 3. THE GAME ROOM (the duel) -->
  <section x-show="view === 'game'" x-cloak class="panel">
    <header class="row between">
      <h1 x-text="statusText"></h1>
      <button @click="backToLobby()">← lobby</button>
    </header>

    <div class="scoreboard">
      <span class="player" :class="{ active: isActive('p1'), win: isWinner('p1') }">
        <span x-text="p1Name"></span> <b x-text="score('p1')"></b>
      </span>
      <span class="vs">vs</span>
      <span class="player" :class="{ active: isActive('p2'), win: isWinner('p2') }">
        <b x-text="score('p2')"></b> <span x-text="p2Name"></span>
      </span>
    </div>

    <!-- shared table: EVERYONE sees the same board -->
    <div class="board" x-show="state.status === 'playing' || state.status === 'finished'">
      <template x-for="(card, i) in state.cards" :key="card.id">
        <button class="card"
                :class="{ up: card.flipped || card.matched, matched: card.matched }"
                :disabled="isDisabled(i)"
                @click="flip(i)">
          <span x-text="face(card)"></span>
        </button>
      </template>
    </div>

    <div class="waiting" x-show="state.status === 'waiting'">
      <p>Share this code with your opponent:</p>
      <div class="code" x-text="state.id"></div>
      <p class="muted">waiting for them to join…</p>
    </div>

    <div class="chat">
      <div class="messages" x-ref="gameLog">
        <template x-for="(m, i) in state.messages" :key="i">
          <div class="msg"><b x-text="m.name + ':'"></b><span x-text="m.text"></span></div>
        </template>
      </div>
      <form @submit.prevent="sendChat()" class="row">
        <input x-model="draft" :placeholder="chatPlaceholder" maxlength="200">
        <button>send</button>
      </form>
    </div>
  </section>

</div>
</body>
</html>
