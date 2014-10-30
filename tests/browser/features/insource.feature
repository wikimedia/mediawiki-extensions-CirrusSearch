@clean @filters @insource @phantomjs
Feature: Searches with the insource filter
  Background:
    Given I am at a random page

  Scenario: insource: only includes pages with the source
    When I search for all:insource:pickles
    Then Template:Template Test is in the search results
      But Two Words is not in the search results
      And there is no link to create a new page from the search result

  Scenario: insource: can be combined with other text
    When I search for all:insource:catapult two words
    Then Two Words is the first search result
      But Template:Template Test is not in the search results
      And there is no link to create a new page from the search result

  Scenario: -insource: excludes pages with that in the source
    When I search for all:-insource:pickles pickles
    Then Two Words is the first search result
      But Template:Template Test is not in the search results
      And there is no link to create a new page from the search result

  @wildcards
  Scenario: insource: can take a wildcard
    When I search for all:insource:pickl*
    Then Template:Template Test is the first search result

  @regex
  Scenario: insource:// executes a regular expression
    When I search for all:insource:/kles \[\[Ca/
    Then Template:Template Test is the first search result

  @regex
  Scenario: insource:// can be combined with other filters
    When I search for asdf insource:/\[\[Category/
    Then Catapult is the first search result
    When I search for insource:/\[\[Category/ asdf
    Then Catapult is the first search result

  @regex
  Scenario: insource:// finds text inside of template calls
    When I search for insource:/year_end.*=.*661/
    Then Rashidun Caliphate is the first search result

  @regex
  Scenario: insource:// can find escaped forward slashes
    When I search for insource:/a\/b/
    Then RegexEscapedForwardSlash is the first search result

  @regex
  Scenario: insource:// can find escaped backslash
    When I search for insource:/a\\b/
    Then RegexEscapedBackslash is the first search result

  @regex
  Scenario: insource:// can find escaped dots
    When I search for insource:/a\.b/
    Then RegexEscapedDot is the first search result

  @regex
  Scenario: insource:// can contain spaces
    When I search for RegexSpaces insource:/a b c/
    Then RegexSpaces is the first search result

  @regex
  Scenario: insource:// can find a url
    When I search for all:insource:/show_bug.cgi\?id=52908/
    Then File:Savepage-greyed.png is the first search imageresult

  @regex
  Scenario: insource:// is case sensitive by default but can be made case insensitive
    When I search for insource:/a\.B/
    Then there are no search results
    When I search for insource:/a\.B/i
    Then RegexEscapedDot is the first search result

  @regex
  Scenario: insource:// reports errors sanely
    When I search for all:insource:/[ /
    Then this error is reported: An error has occurred while searching: Regular expression syntax error at 2: expected ']'

  @regex
  Scenario: insource:// doesn't break other clauses
    When I search for insource:/b c/ insource:/a b c/
    Then RegexSpaces is the first search result