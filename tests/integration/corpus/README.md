# Test corpus pre-population

The integration suite (see `../README.md`) normally builds its corpus at run
time: the `BeforeOnce` hooks in `../features/support/hooks.js` create every page
and file over the MediaWiki API and then poll OpenSearch until each document is
indexed. That serial edit→index→poll loop is the slow part of a suite run.

This directory holds an alternative: a **YAML-described corpus** that the
`CirrusSearch:ImportTestCorpus` maintenance script writes straight to the
database, so the whole wiki can then be indexed in a single pass.

> **Status:** this is the pre-population *mechanism*. Wiring it into the
> `cirrus-integration-test-runner` environment build, and trimming the runtime
> `hooks.js` setup so it no longer recreates the static corpus, are follow-ups.

## Prep recipe

Run per wiki, with search updates disabled so the bulk edits enqueue only
no-op jobs (`CirrusSearch\Job\JobTraits::run()` skips when `$wgDisableSearchUpdate`
or `$wgCirrusSearchDisableUpdate` is set). Use the **core** `$wgDisableSearchUpdate`
— `CirrusSearch:ForceSearchIndex` refuses to run when the *Cirrus-specific*
`$wgCirrusSearchDisableUpdate` is set, but is happy with the core flag.

```sh
# environment (prep phase only): $wgDisableSearchUpdate = true

php maintenance/run.php --wiki <db> CirrusSearch:ImportTestCorpus \
    --corpus extensions/CirrusSearch/tests/integration/corpus/example.yaml \
    --target-wiki <logical>

php maintenance/run.php --wiki <db> CirrusSearch:UpdateSearchIndexConfig
php maintenance/run.php --wiki <db> CirrusSearch:ForceSearchIndex

# test phase: re-enable search updates
```

`ForceSearchIndex` re-parses each page, so categories, links and (with the
PdfHandler extension installed) extracted PDF text are all indexed from the DB.

## Script options

| Option | Required | Description |
| --- | --- | --- |
| `--corpus <path>` | yes | The corpus YAML file. |
| `--target-wiki <logical>` | no | Which logical-wiki entries to import. Defaults to the corpus `defaultWiki`. The actual DB is chosen by core's `--wiki`. |
| `--file-root <dir>` | no | Base directory for relative `file:` paths. Defaults to the corpus file's directory. |
| `--dry-run` | no | Parse, validate and report without writing anything. |

The same corpus file is used for every wiki; run the script once per wiki with
the matching `--target-wiki` (the runner maps the logical name to the DB name).

## YAML schema

Pages are organised into tagged **groups** (a flat top-level `pages:` list is also
accepted for simple corpora). A group records the scenario `tags` its pages were
set up for, an optional `description`, and an optional group-level `wiki` default.

```yaml
defaultWiki: cirrustest          # optional; applied to entries without a wiki

groups:
  - tags: ["@setup_main"]        # provenance; a string or a list (quote the @)
    description: Core articles.  # optional, free text
    pages:
      - title: "Catapult"        # required; may be namespace-prefixed
        text: "A [[catapult]] ..."

      - title: "Mangonel"
        redirect: "Catapult"     # convenience for "#REDIRECT [[Catapult]]"

      - title: "Article From File"
        textFile: ../articles/big.txt   # source read from a file (under --file-root)

  - tags: ["@commons", "@filesearch"]
    wiki: commons                # group-level default wiki for every page below
    pages:
      - title: "File:Timeline.pdf"
        file: ../articles/Timeline.pdf  # media path, resolved under --file-root
        text: "Description page text."  # optional File: description
      - title: "User:Foo/common.js"
        model: javascript               # explicit content model
        text: "mw.log('x');"
        wiki: [cirrustest, commons]     # per-page wiki overrides the group default
        tags: ["@js"]                   # optional per-page tags, added to the group's
```

Top level: exactly one of `groups:` or `pages:`.

Group fields:

- **`tags`** — scenario tag(s) this group documents; a string or list. `@`-prefixed
  values must be quoted (`"@setup_main"`), since bare `@…` is invalid YAML.
- **`description`** — optional free text.
- **`wiki`** — default logical wiki for the group's pages (overridable per page).
- **`pages`** *(required)* — the list of page entries.

Page fields:

- **`title`** *(required)* — page title; namespace is taken from the prefix.
- **`text`** — inline source. Required for normal pages unless `textFile` is given;
  optional for file entries (the File: description page); ignored with `redirect`.
  Text beginning with `#REDIRECT` is treated as a redirect for ordering.
- **`textFile`** — read the source/description from a file instead of inline,
  resolved under `--file-root`. Mutually exclusive with `text`.
- **`redirect`** — target title; mutually exclusive with `file`, `text` and
  `textFile`. Implies the wikitext model.
- **`file`** — path to a media file to upload; the title must be in the `File:`
  namespace. Uploads need the wiki configured for the file type (e.g.
  `$wgFileExtensions` and the PdfHandler extension for PDFs).
- **`model`** — explicit content model (`wikitext|javascript|css|json|text`);
  defaults to the model implied by the title (so `User:…/common.js` is JavaScript).
- **`wiki`** — logical wiki name or list; overrides the group default, else `defaultWiki`.
- **`tags`** — optional per-page tags, merged with the group's.

Redirects are always imported after non-redirect pages so their targets exist.

See `example.yaml` for a worked example covering every supported entry type.
