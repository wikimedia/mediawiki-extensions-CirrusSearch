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

  @regex
  Scenario: insource:// can find a url
    When I search for all:insource:/show_bug.cgi\?id=52908/
    Then File:Savepage-greyed.png is the first search imageresult
