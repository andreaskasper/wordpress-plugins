# goo1-seal.mjs — secrets for Claude Bridge without the chat seeing them

`goo1-seal.mjs` runs on **your** computer. It reads one entry from your vault
(a local JSON file or Vaultwarden), encrypts it for a one-time request of a
Claude Bridge site and prints only the sealed envelope. Claude passes the
envelope to the site; only that site can open it, once, within 30 minutes.

## Setup (once)

Put these files in your vault folder, e.g. `P:\Büro\Secrets\`
(inside the Linux environment Claude uses that is `~/mnt/p/Büro/Secrets`):

| File | Purpose |
|---|---|
| `goo1-seal.mjs` | the script (Node 18+, no npm packages needed) |
| `goo1-vault.json` | local entries, see `goo1-vault.example.json` |
| `bw.env` | optional: Vaultwarden machine user, see `bw.env.example` |
| `allowed-sites.txt` | **required**: sites the script may seal for, optionally with the entries each site may get |

Entry format (same as a Vaultwarden **Login** item): name = reference,
username = access key, password = secret key, custom fields `endpoint`,
`region`, `bucket` are non-secret defaults. Custom fields of type *hidden*
(Vaultwarden) or named `secret_…` (JSON file) are treated as secret.

### Vaultwarden machine user
1. Create a user only for this purpose (e.g. `machine@goo1.de`).
2. In your organisation create a collection `WP-Secrets` and give that user access to it only.
3. As that user: Settings → Security → Keys → *View API key* → `client_id` / `client_secret` into `bw.env`, plus its master password and the server URL.
4. The script installs nothing permanently: if `bw` is missing it runs `npx @bitwarden/cli` (pinned version). Its data lives in `.bw-appdata/` next to `bw.env`; the vault is locked again after every run.

`allowed-sites.txt` example:

```
# host            entries this site may receive (none listed = any)
johnlindo.com     wasabi-johnlindo
*.goo1.de
```

The script refuses sites that are not listed, and sites that report a
different address than the one it contacted. In a terminal it asks before
sealing (`--yes` skips that). Errors never print vault content.

## Use

1. Claude calls `secrets_request` on the target site → gets `request_id`.
2. Claude runs (on your computer):
   `node goo1-seal.mjs --item wasabi-johnlindo --site https://example.com --request-id sr_…`
   The script fetches the site's public key itself, so nobody can slip in another key.
3. Claude passes the `envelope` to e.g. `updraft_configure_s3` (`sealed=…`).

`node goo1-seal.mjs --list` shows entry names only.

## Alternatives
- **Copy from another site:** `secrets_export_sealed` on a site that already has the S3 keys (the target must be on that site's *Secret export targets* list in Settings).
- **By hand:** `secrets_request` returns an `inbox_link`; open it in wp-admin, type the secret, then Claude continues with `secret_request_id`.
