# Wash Pal collector

Two small PHP 8 services, no third-party packages.

- `backend/`: API plus a background worker that talks to Wash Pal, converts readings to CSV and caches the result.
- `frontend/`: static page (HTML + JS) that only talks to the backend.
- `tools/mock_washpal.php`: local stand-in for Wash Pal, for testing without the VPN.

## Run

1. Connect to the VPN.
2. `copy backend\.env.example backend\.env` and fill in `WASHPAL_USER` / `WASHPAL_PASS`.
3. Backend: `php -S 127.0.0.1:8081 -t backend/public backend/public/index.php`
4. Frontend: `php -S 127.0.0.1:8080 -t frontend`
5. Open http://127.0.0.1:8080

Needs the `curl` and `simplexml` PHP extensions. Output is written to `backend/data/` (`result.csv`, `server.csv`, `log.txt`).

Without the VPN: `php -S 127.0.0.1:10102 tools/mock_washpal.php`, then set `WASHPAL_BASE_URL=http://127.0.0.1:10102`, user `user`, password `pass`.

## Design

**Frontend/backend communication: polling.** The frontend calls `POST /runs` to start a run and then polls `GET /runs/current` (every second while running). It downloads the file from `GET /runs/current/csv`. Polling is stateless and works with the PHP built-in server; SSE or WebSockets would add complexity without a benefit here.

**The run lives in the backend.** `POST /runs` starts `worker.php` as a detached process and returns immediately. Progress is stored in `backend/data/state.json` behind a file lock.

- **Frontend closed mid-run:** the run continues. Reopening the page reads `/runs/current` and shows the live progress.
- **Second run started:** refused with `409 Conflict` and the current run's state, because the service keeps a single begin/end task per token. A new run is accepted once the previous one is done or failed. A run whose worker has not written for 60 seconds is treated as dead.

**Token handling.** The token is renewed 60 seconds before its `Expires` time (parsed as UTC), and a `401` triggers one renewal and one retry, so expiry cannot surface as an error.

**Politeness to the shared service.** Requests are strictly sequential, with a delay between readings, request timeouts, backoff on failures, and a hard cap of `MAX_REQUESTS`. `command=end` is always sent once `begin` succeeded.

**Self-check.** After `end`, the backend downloads the server's `/csv` and compares it byte for byte with its own; the result is shown as "Matches server CSV".

**CSV details confirmed against the server's CSV:** all valid readings from all nodes in request order; numbers have no trailing zeros; delta is the difference between the rounded Celsius values of consecutive readings of the same node and type.

**Logging.** Every API request and every upstream request is appended to `backend/data/log.txt` with a UTC timestamp. Secrets are never logged.

## Use of AI

GitHub Copilot (chat in VS Code) was used to plan the architecture and to write the initial code, the mock server and this README. I reviewed the result, ran it against the real service and fixed the delta rounding after comparing with the server's CSV.

## Bonus questions

**7.1 Is this approach secure?** Not fully. Wash Pal is reached over plain HTTP with Basic auth, so credentials and tokens are readable on the network (the VPN mitigates this). Credentials sit in a `.env` file, and the backend API has no authentication or rate limiting of its own. Improvements: HTTPS everywhere, secrets from a secret manager, authentication and per-user rate limits on the backend API, a strict CORS/CSRF policy (the backend already rejects foreign origins on state-changing calls), input validation, and short-lived tokens.

**7.2 Why UTC?** UTC has no time zones or daylight saving shifts, so timestamps from different machines and regions are unambiguous and sort and compare correctly.

**7.3 How many 'ŗ' in 'cranberries'?** The word has 3 plain "r" letters. The character in the question is "ŗ" (r with a cedilla), which does not appear in the word, so strictly the answer is 0.
