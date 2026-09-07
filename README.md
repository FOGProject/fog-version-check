# fog-version-check

The service behind **`https://fogproject.org/version/`** — the thing every FOG
install asks "am I running the latest version?".

`packages/web/status/mainversion.php` POSTs the server's own `FOG_VERSION` here
and renders whatever comes back on the About page. That is the only caller in
`FOGProject/fogproject`; the JSON parameters below are a public contract with
anything else already pointed at this URL.

## Endpoints

| Request | Answer |
|---|---|
| `POST version=<FOG_VERSION>` | HTML: up to date, or the latest of all three branches |
| `?stable` `?dev` `?alpha` | JSON, any combination — e.g. `{"stable":"1.5.10.2254","dev":"1.5.10.2439","alpha":"1.6.0-beta.4691"}` |
| `/version.php` | The bare `VERSION` constant from `config.php`. Vestigial — see that file |

`alpha` is the **beta** branch (`working-1.6`). The parameter predates the branch
being called beta and is not renamed, because callers exist that we cannot see.

## Where each version comes from

| Branch | Source |
|---|---|
| `stable` | `packages/web/lib/fog/system.class.php` on raw.githubusercontent |
| `dev-branch` | same path, that branch |
| `working-1.6` | the release prefix from `packages/web/src/Base/System.php`, **plus a commit count from the GitHub compare API** |

That third row is the whole reason this repo exists rather than a file edited in
place on the host.

`FOG_VERSION` on `working-1.6` is `git rev-list master..HEAD --count`. Since
[GH-1513](https://github.com/FOGProject/fogproject/pull/1513) it is **generated**
at commit, checkout and install time into the gitignored
`packages/web/commons/version.php`, because holding a per-branch count in a
tracked file made every branch open at once conflict on the same line. So
`System.php` now carries only the bare release fallback, `1.6.0-beta`, with no
build number — and scraping it the way `stable` and `dev-branch` are scraped
returns a string no running 1.6 server can ever equal. Every beta install gets
told it is out of date, forever.

The count is recovered from GitHub's compare API, which reports exactly that
number as `ahead_by`:

```
/repos/FOGProject/fogproject/compare/master...working-1.6?per_page=1&page=2
```

`per_page=1&page=2` is load-bearing. The `files[]` diff of `master...working-1.6`
is attached to page 1 only, so asking for page 2 takes the response from ~2.5 MB
to ~13 KB while `ahead_by` stays present on every page. Unauthenticated GitHub
allows 60 requests an hour per IP; the 300-second cache makes this 12. The API
also rejects any request that sends no `User-Agent`.

## Deploy

The document root for `fogproject.org` is `/var/www/html/website`, so the served
path is **`/var/www/html/website/version`** — a clone at `/var/www/html/version`
is not reachable at `fogproject.org/version/`.

```sh
cd /var/www/html/website
mv version version.pre-repo                      # keep the old copy until this is proven
git clone https://github.com/FOGProject/fog-version-check.git version
chown -R nginx:nginx version/cache               # ONLY cache/ -- see below
php version/bin/diagnose.php
```

Only `cache/` is writable by the web user. Nothing that serves a request can
then rewrite `index.php`, which is the point of not making the checkout writable.

The three `cache/*.txt` files are runtime state and gitignored. Deleting them
costs one GitHub fetch.

## Updating

```sh
cd /var/www/html/website/version && git pull && systemctl reload php-fpm
```

**The reload is not optional if opcache is set to skip timestamp checks** — the
classic symptom is replacing the file and seeing the old behavior unchanged.
`bin/diagnose.php` reports the setting.

## Diagnosing

```sh
sudo -u nginx php /var/www/html/website/version/bin/diagnose.php
```

Checks the deploy (right file on disk, opcache revalidation), GitHub (all three
sources, the compare API, the rate limit) and the cache (writable, contents,
age) — independently, sharing no code with `index.php`, so it can tell you the
endpoint itself is broken. Exit status is non-zero if anything failed.

## The agent release manifest

`agent-stable.json` and `agent-stable.json.sig` are served from this repo as
**static files**, and they are what makes fog-agent self-update work. An
agent fetches the manifest to find out what a version *is* — the sha256, size
and URL of each artifact — then fetches the `.sig` beside it and verifies both
against a root compiled into its own binary.

    https://fogproject.org/version/agent-stable.json
    https://fogproject.org/version/agent-stable.json.sig

That URL is compiled into every agent (`DefaultManifestURL`), which is why the
filename names its channel: it cannot be changed for an agent already
deployed, so a beta manifest has to be a sibling file rather than a rename.

**Nothing here is trusted, and that is the design.** This service can serve
nothing, an old manifest (which the agent refuses by sequence floor) or the
real one — there is no fourth option, because only the signature decides what
an agent will install. Hosting the file is therefore free of security
consequence; the root key that would matter is offline and not in this repo.

### How it gets here

`.github/workflows/publish-agent-manifest.yml`, run from the Actions tab after
a fog-agent release (**Run workflow**, optionally naming a tag). It downloads
that release's artifacts, hashes them, merges the versions already in
`agent-stable.json`, signs, and commits both files in one commit.

It signs *here* rather than in fog-agent's release workflow because it runs
after the release exists: the artifacts it hashes are the ones people
download, so it cannot describe pre-signing bytes that Authenticode later
rewrote. Two secrets are needed, both from `build/mint-signing-ca.sh` in
fog-agent — see `docs/signing/release-signing-ca.md` there:

| Secret | Value |
|---|---|
| `FOG_AGENT_SIGNING_LEAF_KEY` | contents of `leaf.key` |
| `FOG_AGENT_SIGNING_LEAF_CRT` | contents of `leaf.crt` |

The **root** key is never here. It lives offline; its certificate is compiled
into the agent. The leaf is short-lived and reissuable under the same root, so
a leaked leaf costs a reissue and touches no deployed machine.

### Two traps

- **Serve it as a static file.** The signature is over the manifest's exact
  bytes. Anything that re-encodes the JSON — `jq`, an editor, generating it
  from `index.php` — invalidates every signature, and agents report
  `signature_invalid`, which names the wrong cause.
- **Both files move together.** The agent fetches the manifest and the `.sig`
  as two requests. A commit that updated one without the other leaves a window
  where every polling agent in the world reads a mismatched pair. The workflow
  commits them together for this reason; a manual fix must too.

### Versions accumulate

One file offers every release ever published, because `Manifest.Find()` looks a
version up by exact key. A manifest holding only the newest release answers
`no_artifact` for anything older — and naming an older version is the only
recovery from a build that installs, starts and polls perfectly well and then
behaves badly, which local rollback cannot catch. Growth is about 1.8 KB per
release. To withdraw a version, delete its key from `agent-stable.json` before
the next release merges it forward.
