# Wash Pal

The backend talks to Wash Pal, converts the readings to CSV and caches the result. The frontend only talks to the backend.

- `backend/`: HTTP API and a background worker
- `frontend/`: static HTML and JS page
- `tools/mock_washpal.php`: fake Wash Pal service for testing without the VPN

## How I run it

1. Connect to the VPN.
2. Run `copy backend\.env.example backend\.env` and fill in `WASHPAL_USER` and `WASHPAL_PASS`.
3. Start the backend: `php -S 127.0.0.1:8081 -t backend/public backend/public/index.php`
4. Start the frontend: `php -S 127.0.0.1:8080 -t frontend`
5. Open http://127.0.0.1:8080

PHP needs the `curl` and `simplexml` extensions. Output goes to `backend/data/`: `result.csv` is the generated file, `server.csv` is the copy downloaded from Wash Pal for comparison, and `log.txt` is the request log.

To test without the VPN, run `php -S 127.0.0.1:10102 tools/mock_washpal.php` and set `WASHPAL_BASE_URL=http://127.0.0.1:10102` with user `user` and password `pass`.

## Design

### How the frontend and backend communicate

The frontend polls. It starts a run with `POST /runs`, then calls `GET /runs/current` every second while a run is active and every five seconds otherwise. The CSV comes from `GET /runs/current/csv`. Polling needs no open connection and works with the PHP built-in server. SSE or WebSockets would add code without giving this page anything it needs.

### Where the run lives

`POST /runs` starts `worker.php` as a detached process and returns right away. The worker keeps its progress in `backend/data/state.json`, guarded by a file lock.

If the user closes the frontend mid-run, the run keeps going. Reopening the page reads `/runs/current` and shows the current progress.

If the user starts a second run while one is active, the backend answers `409 Conflict` together with the state of the current run. Wash Pal keeps one begin/end task per token, so two runs at once would corrupt each other. A new run is accepted once the previous one is done or has failed. A run whose worker has not written anything for 60 seconds counts as dead.

### Token

The token is renewed 60 seconds before its `Expires` time, which is parsed as UTC. If Wash Pal still answers `401`, the backend renews once and retries the request, so an expired token never reaches the user.

### Load on the shared service

Requests go out one at a time, with a short delay between them, a timeout on each one, backoff after failures and a hard cap set by `MAX_REQUESTS`. Once `begin` has succeeded, `command=end` is always sent, even if collection fails.

### Checking the output

After `end`, the backend downloads the server's `/csv` and compares it byte for byte with its own. The page shows the result as "Matches server CSV".

The rules that produce an identical file: every valid reading from every node goes in, in request order; numbers have no trailing zeros; and the delta is the difference between the rounded Celsius values of consecutive readings for the same node and type.

### Logging

Every API request and every request to Wash Pal is appended to `backend/data/log.txt` with a UTC timestamp. Tokens and credentials are not logged.

## Use of AI

GitHub Copilot (chat in VS Code) helped plan the architecture and wrote the first version of the code pretty much MVP, the mock server. I reviewed it and ran it against the real service. Copilot also fixed the delta rounding after the first real run showed a mismatch with the server's CSV.

## Bonus questions

**7.1 Is this approach secure?** Not fully. Wash Pal is reached over plain HTTP with Basic authentication, so credentials and tokens can be read on the network. The VPN reduces that risk but does not remove it. Credentials sit in a `.env` file, and the backend API has no authentication or rate limiting of its own. To harden it, using HTTPS, load secrets from a secret manager, add authentication and per-user rate limits to the API, keep a strict CORS policy (the backend already refuses foreign origins on state-changing requests), validate input, and prefer short-lived tokens.

**7.2 Why are all time values in UTC?** UTC has no time zones or daylight saving changes, so timestamps from different machines and regions mean the same thing and sort correctly.
