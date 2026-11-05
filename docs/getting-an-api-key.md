# Getting an Eventbrite API key and token

Eventbrite gives you three credentials from one page, each with a different job.

## The page

**https://www.eventbrite.com/account-settings/apps** → Developer Portal →
**Get a Free API Key** (log in first).

You end up with a section like:

| Row | What it is | Pass it to |
|---|---|---|
| **API key** | OAuth `client_id` — public identifier | `EventbriteOAuth` `$clientId` |
| **Client secret** | For server-side token exchanges — treat as a password | `EventbriteOAuth` `$clientSecret` |
| **Private token** | A personal bearer token valid as *your account* | `EventbriteClient` token |
| **Public token** | Anonymous read-only token for public event data — not used in this client | — |

## Using the private token (fastest start)

Paste it wherever your app reads config from (env var, dotenv, secret store):

```bash
# .env (never commit)
export EVENTBRITE_TOKEN="your-private-token-here"
```

```php
$client = new EventbriteClient($http, $factory, $factory, $token);
$client->me(); // works immediately
```

The private token acts as your account: it sees everything your Eventbrite user
sees. Keep it server-side only — never in client-side code, never in logs.

## Using OAuth (other users, or your other accounts)

With only an API key + client secret:

1. Register your redirect URI in the app's settings (exact string match, e.g.
   `https://example.com/oauth/eventbrite/callback`; `http://localhost:8766/...`
   works in development).
2. Send users to `EventbriteOAuth::authorizeUrl($oauth->newState())`.
3. On the callback, verify the state, then exchange:
   `EventbriteOAuth::exchangeCode($code)` → `['access_token' => ...]`.
4. Store the access token yourself (DB, cache) and pass it to
   `EventbriteClient` for that user's calls.

Tokens can expire (for example when the user changes their password) — the API
answers with a 401 `INVALID_AUTH`; send the user through the flow again. If
they already approved your app once, Eventbrite will not ask them to confirm
again.

## Hygiene checklists

- [ ] Credentials live only in config/env, never in code or chat.
- [ ] Client secret and tokens never logged (this client never logs them).
- [ ] Delete unused API keys from the keys page.
- [ ] If a token leaks: revoke it on the same page and issue a new one.
