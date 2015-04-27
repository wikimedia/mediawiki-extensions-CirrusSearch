@clean @filters @insource @api
Feature: Searches with the insource filter
  @wildcards
  Scenario: insource: can take a wildcard
    When I api search for all:insource:pickl*
    Then Template:Template Test is the first api search result

  @regex
  Scenario: insource:// executes a regular expression
    When I api search for all:insource:/kles \[\[Ca/
    Then Template:Template Test is the first api search result

  @regex
  Scenario: insource:// can be combined with other filters
    When I api search for asdf insource:/\[\[Category/
    Then Catapult is the first api search result
    When I api search for insource:/\[\[Category/ asdf
    Then Catapult is the first api search result

  @regex @highlighting
  Scenario: insource:// finds text inside of template calls
    When I api search for insource:/year_end.*=.*661/
    Then Rashidun Caliphate is the first api search result

  @regex
  Scenario: insource:// can find escaped forward slashes
    When I api search for insource:/a\/b/
    Then RegexEscapedForwardSlash is the first api search result

  @regex
  Scenario: insource:// can find escaped backslash
    When I api search for insource:/a\\b/
    Then RegexEscapedBackslash is the first api search result

  @regex
  Scenario: insource:// can find escaped dots
    When I api search for insource:/a\.b/
    Then RegexEscapedDot is the first api search result

  @regex
  Scenario: insource:// can contain spaces
    When I api search for RegexSpaces insource:/a b c/
    Then RegexSpaces is the first api search result

  @regex
  Scenario: insource:// is case sensitive by default but can be made case insensitive
    When I api search for insource:/a\.B/
    Then there are no api search results
    When I api search for insource:/a\.B/i
    Then RegexEscapedDot is the first api search result

  @regex
  Scenario: insource:// doesn't break other clauses
    When I api search for insource:/b c/ insource:/a b c/
    Then RegexSpaces is the first api search result

  @regex
  Scenario: insource:// for other complex regexes finds answers and doesn't spin forever
    When I api search for all:insource:/[ab]*a[cd]{50,80}/
    Then RegexComplexResult is the first api search result

  @regex
  Scenario: insource:// reports errors sanely
    When I api search for all:insource:/[ /
    Then this error is reported by api: Regular expression syntax error at 2: expected ']'

  @regex
  Scenario: insource:// for some complex regexes fails entirely
    When I api search for all:insource:/[ab]*a[ab]{50,80}/
    Then this error is reported by api: Regular expression is too complex.  Learn more about simplifying it [[mw:Special:MyLanguage/Help:CirrusSearch/RegexTooComplex|here]].

