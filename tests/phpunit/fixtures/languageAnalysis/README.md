# Language Analysis Fixtures #

The json `.config` for analysis fixtures has evolved into a mini domain-specific language
that is sufficiently complex (and its usage in specific cases is sufficiently opaque) that
a little documentation seemed to be in order.

## Fixture Test Types ##

**Basic Tests:** these tests get run for every language/`.config` file
* **core:** no plugins, no extra CirrusSearch config.
* **prod:** all plugins + CirrusICUConfig (this should be what's in production).

**Extended Tests:** these tests are only run if specified
* **no\_cic:** all plugins, but no extra CirrusICUConfig config.
* **lang:** all language plugins, + CirrusICUConfig.
* **icu:** ICU plugins (`extra`, `analysis-icu`) + CirrusICUConfig; should enable
  `icu_folding` and `icu_tokenization`.
* **text:** text plugins (`extra-analysis-homoglyph`, `extra-analysis-textify`) +
  CirrusICUConfig; `icu_folding` should not be enabled, but `textify` should enable
  `icu_tokenization`.

**Note:** Enabling CirrusICUConfig sets `'CirrusSearchUseIcuFolding' => 'default',
'CirrusSearchUseIcuTokenizer' => 'default'`

## `.config` ##

An empty config (`{}`) indicates that the Basic Tests should be run with a language code
that is the same as the name of the config.

A `langCode` entry specifies that this fixture should be run with the specified language
code, rather than the name of the config. `uk-noextra.config` uses the `langCode` for
Ukrainian, `uk`.

A `withoutPlugins` entry specifies a list of plugins that should be *deleted* from any
list of plugins enabled by a given test. `uk-noextra.config` runs without
`extra-analysis-ukrainian`, in order to test everything else without that specific plugin.

Setting `extended_tests` to `true` runs the Extended Tests for this config.

The `expected` entry is a bit more complicated. Consider any of the following in
`a.config`:
* `"expected":"b"` indicates that every test run for `a` should have the same results as
  the corresponding test for `b`.
  * For example, `en-ca` should have the same results as `en`.
* `"expected.core":"b"` indicates that the **core** test for `a` should have the same
  results as the **core** test for `b`.
  * Several languages have **core** results that are the same as `default`.
* `"expected.lang":"a.core"` indicates that the **lang** test for `a` should have the same
  results as the **core** test for `a`.
  * Languages that don't use any language-specific plugins generally have the same results
    for **lang** and **core** tests.
  * `"expected.core":"b"` is the same as `"expected.core":"b.core"`.

A more specific `expected` entry overrides a less specific entry. If `"expected":"b"` and
`"expected.core":"c"` are both used, all tests for `a` besides **core** should have the
same results as `b`, and the **core** test for `a` should have the same results as the
**core** test for `c`.

Behavior for multiple equally specific entries, such as `"expected":"b"` and
`"expected":"c"` is not well defined. Don't do that.

### Some Debugging Advice ###

`expected` entries save a lot on repetitive fixtures, and can highlight when languages
that are expected to behave the same diverge. However, debugging them can be a little
confusing when they separate the source of a problem from its problematic output.

Fixture configs are processed one at a time and not aware of the contents of other
configs. The `expected` config just overrides the default naming convention. If `a`
expects the same results as `b`, and `b` expects the same results as `a`, their fixture
files will just be generated with swapped names, and will not necessarily be the same.

Configs are run in whatever order `findfixtures()` returns them. So if no relevant
`.expected` file exists (say, because you are generating it for the first time) and
`x.config` contains  `"expected.core":"z"`, `x` may run before `z`, generating
`z.core.expected`. When `z` runs later, it may generate an error, reporting that the
generated results don't match `z.core.expected`, even though everything is fine with `z`
(and `x` is the real problem).

If the solution to the problem like this isn't immediately obvious, decouple `x` and `z`
and inspect their various `.expected` results individually.

## Special Fixtures ##

* The `default` config uses a non-language language code (i.e., "default") and runs
  extended tests. It is used as a reference for any language that results in no
  customization for a given test scenario.

* The `asciifolder` fixtures have no specific `.config` associated with them. Instead, any
  language that is set up to be built (i) with the default analyzer, (ii) plus
  `asciifolding`, and (iii) without any `icu_folding` customizations should give the same
  `asciifolder` results.
  * Some languages only expect to match `asciifolder` **core** (which has no `icu_folding`
    and thus no `icu_folding` customizations), while their **prod** results differ from
    `asciifolder`'s **prod** results due to those `icu_folding` exceptions.

* Similarly, the `icu_tok` **icu** fixture has no specific `.config` associated with it.
  Languages that (i) allow `icu_tokenization` and (ii) have no other customization will be
  the same as `default` when the ICU plugin is not enabled, and will all the same as each
  other when it is enabled.


## `expected` Mappings ##

Some general patterns:

* Languages that run extended tests, but that either have no language customization, or
  have customization that is provided via Elasticsearch (i.e., not via plugins) will
  generally have the same **lang** and **core** results.
* Languages with default analyzers except for having `icu_tokenization` enabled will
  generally have the same results as `default`, except for their **icu** tests, which will
  all be the same (and match the `icu_tok` **icu** fixture).
* Languages that depend on plugins for customization will generally have their **core**
  results be the same as `default`.
* Languages with generally complex analyzers are configured for extended tests, just to
  keep an eye out for unexpected changes.

Some languages should generally have the same results as others.

> Keep in mind that if `a` expects the same output as `b`, but `b` also expects its
> **lang** results to be the same as its **core** results, both `a` and `b` will need an
> entry for `"expects.lang":"b.core"`.

* `ary` and `arz` should have the same results as `ar`.
* `bs`, `hr`, and `sr` should have the same results as `sh`.
* `en-ca`, `en-gb`, and `simple` should have the same results as `en`.
* `ms` should have the same results as `id`.
* `nb` and `nn` should have the same results as `no` (for now—some variety-specific
  customization may eventually occur).
* `az` and `crh` have the same bare bones analysis configuration in **core** tests. Since
  neither is "based on" the other, they share their **core** results in the `az_crh`
  fixtures.
* Similarly, `kk` and `tt` share the `kk_tt` fixtures.
