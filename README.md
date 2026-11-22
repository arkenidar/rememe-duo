# Rememe Duo

A minimal two-screen memory duel in plain PHP + Alpine.js. One shared board, a
lobby, and chat — with zero dependencies beyond a single Alpine `<script>` tag.

Everything is a "room": the lobby is a room, and each game is a room with a
board attached. Each room is just a JSON file in `rooms/`. Learn one room and
you understand the whole app.

## Run it

```bash
cd rememe-duo
php -S 0.0.0.0:8000
```

Open `http://localhost:8000` and pick a name to enter the lobby.

- **Same machine, two tabs:** open the page in two browser tabs. Each tab gets
  its own URL + player token, so each tab is a separate player. (No incognito
  needed — identity is in the URL, not a cookie.)
- **Two devices:** run the server on your LAN and open
  `http://<your-LAN-IP>:8000` on both. Player 1 clicks "new game" and shares
  the 6-letter code; Player 2 types it (or clicks it in the lobby list).

> Offline note: download the Alpine file to `alpine.min.js` next to this README
> and change the `<script src>` in `index.php` accordingly.

## The golden rule

Data lives in **one place** (the room file on the server). Each browser keeps a
mirror and re-renders whenever it changes:

```
fetch/assign → state changes → Alpine redraws
```

The frontend never touches the DOM directly — it only assigns `this.state`, and
the view follows. A card flip, a chat message, and a turn change are all the
same thing: *data in, view out.*

## File tour (one file = one job)

| File | Job |
|------|-----|
| `config.php` | every knob: pairs, emojis, delays, poll rate, code length, message caps |
| `store.php` | load/save room JSON files, with `flock()` so two devices don't clash |
| `api.php` | the JSON API: `hello / lobby / chat / create / join / flip / state` |
| `index.php` | the Alpine template (name → lobby → game room) |
| `script.js` | the Alpine component: fetch data, assign state, poll |
| `style.css` | layout |
| `rooms/` | runtime data — `lobby.json` + one `<code>.json` per game |

## The API in one line each

- `hello` — gives this browser a player token
- `lobby` — join the lobby (returns people + open games)
- `chat`  — append a message to any room
- `create` — make a game room (you are player 1)
- `join`  — join by code (you are player 2, board shuffles)
- `flip`  — your move (turn-gated)
- `state` — the current room (the polling heartbeat)

## Ways to extend it

- More pairs / new symbols → edit `config.php` (one number / one string).
- Swap the file store for a database → only `store.php` changes.
- Swap polling for WebSockets → `script.js` + one endpoint.
- A "rematch" button → reset the game room's board/status (a few lines in `api.php`).

## Honest simplifications (fine for a game)

- Player identity is a token in the URL — anyone who sees the URL can act as
  you. A real app would use signed cookies / a login.
- Everything is `GET` query params; a "proper" API would use POST for writes.
- The lobby keeps names it has seen (capped); there's no auto-cleanup of old rooms.
