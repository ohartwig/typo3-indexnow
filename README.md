<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# IndexNow for TYPO3

Tells search engines within minutes which pages of a TYPO3 site were
published, changed, scheduled or removed — through
[IndexNow](https://www.indexnow.org/documentation), which Bing, Yandex, Seznam,
Naver, Yep and Amazon share among themselves. Bing's index is what ChatGPT
search and Copilot read, so a page announced here can be citable there the
same day instead of whenever a crawler happens to return.

For TYPO3 v14. Built on the framework-agnostic
[koh/indexnow](https://github.com/ohartwig/indexnow).

## Install and switch on

```bash
composer require koh/typo3-indexnow
vendor/bin/typo3 extension:setup
```

Then add the set to each site that should announce, in
`config/sites/<site>/config.yaml`:

```yaml
dependencies:
  - koh/indexnow
```

That is all. There is no key to generate and no file to upload:

- **The key is derived per host** from the installation's encryption key
  (HMAC-SHA256). Every replica computes the same key, every host gets its own,
  and nothing has to be stored. A site with a key already registered elsewhere
  can set it in the site setting `indexnow.key`.
- **The key file is served by a middleware** at `/<key>.txt` — or below the
  site's base path, with `keyLocation` sent accordingly.
- **A scheduler task is created on `extension:setup`**, running `indexnow:run`
  every five minutes. An existing task is never touched, so disabling or
  re-timing it in the backend sticks. The TYPO3 scheduler has to run, of course.
- **The whole site is announced once**, the first time one of its hosts is
  active. That is the go-live; afterwards only changes are sent.

## What never gets announced

Only the **Production** application context announces anything. On top of
that, hosts are checked by the library's host policy, which refuses
`.localhost`, `.test`, `.invalid`, `.internal`, `.local`, IP addresses,
single-label names and any host with a `dev`, `staging`, `preview` (and
similar) label left of the registrable domain — so `shop.dev.example.com` stays
silent even when someone runs it in Production. Further suffixes can be denied
per site with `indexnow.deniedHostSuffixes`.

On a host that does not announce, the key file does not exist either: the
request falls through to TYPO3's regular 404.

Regardless of host:

- **Access-restricted pages** — a frontend user group on the page, its
  translation or an ancestor that extends to subpages — are never announced,
  not even when they change. Announcing would publish a URL meant to stay
  private.
- **Page types that are not documents** are skipped: links, shortcuts, mount
  points, spacers, folders, the recycler (`indexnow.excludedDoktypes`, same
  default as the core XML sitemap).
- **Workspace drafts** are ignored until they are published.

## What gets announced, and when

| Trigger | Announced |
|---|---|
| A page or content element is saved, moved, deleted or restored in the live workspace | The page, in the language that changed — every language when visibility, access, type or SEO flags changed; for a move, the old page as well |
| A workspace is published | What became public |
| A start or end time passes | The page, although nobody saved anything |
| The first run with an active host | Every visible, indexable page in every language |
| `indexnow:submit-all` | The same, on demand |

A changed page is announced **even when it is now hidden, deleted or
`noindex`**: the engine has to re-crawl to learn that, and IndexNow asks for
removals explicitly. Its parent page is announced with it by default
(`indexnow.includeParentPage`), because a new article changes the list it
appears in. The go-live submission is strict instead and lists only pages meant
to be found.

Saving a page twenty times before the next run announces it once.

## Delivery

Changes are queued (`tx_kohindexnow_queue`, one row per page and language) and
sent by `indexnow:run`: one request per host, protocol limits respected.

- **Accepted** — the rows are removed.
- **Retryable** (429, 5xx, no connection, 403 while the engine cannot fetch the
  key file yet) — the rows wait with exponential backoff from one minute to six
  hours, honouring `Retry-After`, and are given up after eight attempts.
- **Rejected** (400, 422) — the rows are removed and the failure is logged,
  because the same request would be rejected again. The scheduler task then
  exits with status 1.

Nothing expensive happens during an editor's save: the hook buffers record
references and writes the queue once at the end of the DataHandler run. URLs
are generated later, by the worker, from the raw page record — which is also
what makes a URL for a deleted page possible.

HTTP goes through TYPO3's configured client (proxy, CA bundle and
`HTTP.allowed_hosts.indexnow` apply) with a ten-second timeout and without
following redirects.

## Commands

```bash
vendor/bin/typo3 indexnow:status        # per host: active or why not, key file URL, queue size
vendor/bin/typo3 indexnow:run           # what the scheduler task runs
vendor/bin/typo3 indexnow:submit-all    # queue every indexable page again (all sites or the given ones)
```

`indexnow:status` is the answer to "why is nothing being sent?".

## Site settings

| Setting | Default | |
|---|---|---|
| `indexnow.enabled` | `true` | Switch for the site |
| `indexnow.key` | empty | Empty: derived per host. Set only to keep an existing key |
| `indexnow.excludedDoktypes` | `3, 4, 6, 7, 199, 254, 255` | Page types never announced |
| `indexnow.includeParentPage` | `true` | Also announce the parent of a changed page |
| `indexnow.deniedHostSuffixes` | empty | Additional hosts never announced |

## Extending

Three PSR-14 events:

- `ResolveRecordPagesEvent` — which pages show a changed record. The default
  is the page it is stored on; a record rendered on a detail page elsewhere
  (news, products, events) needs a listener from the extension that owns it.
- `BeforeUrlsSubmittedEvent` — add URLs that change with pages, such as a feed
  or an `llms.txt`. URLs on other hosts are dropped afterwards.
- `AfterUrlsSubmittedEvent` — what was sent and how the engine answered, for
  monitoring.

## Independence

An independent implementation, written from the IndexNow specification.

## Licence

MIT. See [LICENSE](LICENSE).
