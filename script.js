// script.js — the whole frontend logic.
// The golden rule: we NEVER touch the DOM. We only change `this.state`,
// and Alpine redraws everything from it. Data in, view out.
//
// So "updating the screen" is just:
//   1. ask the server (fetch)
//   2. assign the answer to `this.state`
//   3. Alpine re-renders automatically

async function api(action, params = {}) {
  const q = new URLSearchParams({ action, ...params });
  const res = await fetch('api.php?' + q.toString());
  const data = await res.json();
  if (!data.ok) throw new Error(data.error || 'something went wrong');
  return data;
}

// The Alpine component as a plain global function.
// Alpine calls it from x-data="memoryGame()" and gets this object back.
// (We can't use Alpine.data() here: the CDN build auto-starts Alpine in a
//  microtask right after its <script defer> runs, before this file could
//  register via alpine:init — so script.js is loaded BEFORE Alpine instead.)
window.memoryGame = () => ({
    // ---- local UI state (not shared) ----
    view: 'name',          // 'name' | 'lobby' | 'game'
    name: '',
    joinCode: '',
    draft: '',
    token: null,
    roomId: null,
    you: null,             // 'p1' | 'p2' | null (your seat in a game)

    // ---- the shared state (mirror of the room file on the server) ----
    state: {
      people: [], messages: [], openGames: [],
      players: {}, cards: [], scores: {}, turn: null, status: null,
    },

    // ---- boot: who/where am I? (read from the URL) ----
    async boot() {
      const q = new URLSearchParams(location.search);
      this.token = q.get('player');
      this.name = q.get('name') || '';
      this.roomId = q.get('room');

      if (this.token && this.roomId) {
        this.view = 'game';
        await this.refresh().catch(() => {});
        this.startPolling();
      } else if (this.token) {
        await this.enterLobby().catch(() => {});
        this.startPolling();
      }
      // no token yet -> stay on the name screen
    },

    // ---- identity ----
    async enterName() {
      const name = this.name.trim();
      if (!name) return;
      const r = await api('hello');
      this.token = r.token;
      this.setUrl();
      await this.enterLobby();
      this.startPolling();
    },

    async enterLobby() {
      const r = await api('lobby', { token: this.token, name: this.name.trim() });
      this.roomId = CONFIG.lobby;
      this.apply(r);
      this.view = 'lobby';
      this.setUrl();
    },

    // ---- lobby actions ----
    async createGame() {
      const r = await api('create', { token: this.token, name: this.name.trim() });
      this.roomId = r.room.id;
      this.apply(r);
      this.view = 'game';
      this.setUrl();
      this.startPolling();
    },

    async joinGame(code) {
      const c = (code || this.joinCode || '').trim().toUpperCase();
      if (!c) return;
      const r = await api('join', { token: this.token, name: this.name.trim(), room: c });
      this.roomId = r.room.id;
      this.apply(r);
      this.view = 'game';
      this.setUrl();
      this.startPolling();
    },

    async backToLobby() {
      this.roomId = null;
      await this.enterLobby();
    },

    // ---- in-game actions ----
    async flip(i) {
      if (this.isDisabled(i)) return;
      try {
        const r = await api('flip', { token: this.token, room: this.roomId, card: i });
        this.apply(r);
      } catch (e) {
        await this.refresh().catch(() => {}); // someone else moved — just resync
      }
    },

    async sendChat() {
      const text = this.draft.trim();
      if (!text) return;
      this.draft = '';
      try {
        const r = await api('chat', { token: this.token, room: this.roomId, text });
        this.apply(r);
      } catch (e) {
        await this.refresh().catch(() => {});
      }
    },

    // ---- the heartbeat: keep my mirror in sync with the shared room ----
    async refresh() {
      if (!this.token || !this.roomId) return;
      const r = await api('state', { token: this.token, room: this.roomId });
      this.apply(r);
    },

    startPolling() {
      if (this._timer) return;
      this._timer = setInterval(() => this.refresh().catch(() => {}), CONFIG.pollMs);
    },

    // ---- helpers ----
    apply(r) {
      const prevGames = this.state.openGames;
      this.state = r.room;
      this.state.openGames = r.openGames || prevGames || [];
      this.you = r.you;
      // keep the chat scrolled to the bottom
      this.$nextTick(() => {
        ['lobbyLog', 'gameLog'].forEach((ref) => {
          const el = this.$refs[ref];
          if (el) el.scrollTop = el.scrollHeight;
        });
      });
    },

    setUrl() {
      const q = new URLSearchParams();
      if (this.token) q.set('player', this.token);
      if (this.name) q.set('name', this.name);
      if (this.roomId && this.roomId !== CONFIG.lobby) q.set('room', this.roomId);
      history.replaceState(null, '', '?' + q.toString());
    },

    // ---- tiny getters used by the template ----
    get p1Name() { return this.state.players?.p1?.name || 'Player 1'; },
    get p2Name() { return this.state.players?.p2?.name || 'Player 2'; },
    get statusText() {
      const s = this.state;
      if (s.status === 'waiting') return 'waiting for opponent…';
      if (s.status === 'finished') {
        if (s.winner === 'tie') return "it's a tie!";
        return '🏆 ' + (s.winner === 'p1' ? this.p1Name : this.p2Name) + ' wins!';
      }
      return "it's " + (s.turn === 'p1' ? this.p1Name : this.p2Name) + "'s turn";
    },
    get chatPlaceholder() {
      return this.view === 'lobby' ? 'chat with everyone…' : 'chat with your rival…';
    },

    score(slot) { return this.state.scores?.[slot] ?? 0; },
    isActive(slot) { return this.state.status === 'playing' && this.state.turn === slot; },
    isWinner(slot) { return this.state.status === 'finished' && this.state.winner === slot; },
    face(card) { return (card.flipped || card.matched) ? card.emoji : '◆'; },
    isDisabled(i) {
      const c = this.state.cards[i];
      if (!c) return true;
      if (this.state.status !== 'playing') return true;
      if (this.state.turn !== this.you) return true;
      if (c.flipped || c.matched) return true;
      if (this.state.mismatch) return true;
      return false;
    },
});
