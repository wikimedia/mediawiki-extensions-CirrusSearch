Feature: Full text search with filters
  Background:
    Given I am at a random page

  @filters
  Scenario: intitle: only includes pages with the title
    When I search for intitle:catapult
    Then Catapult is in the search results
    And Amazing Catapult is in the search results
    But Two Words is not in the search results

  @filters
  Scenario: intitle: can be combined with other text
    When I search for intitle:catapult amazing
    Then Amazing Catapult is the first search result
    And Two Words is not in the search results

  @filters
  Scenario: -intitle: excludes pages with part of the title
    When I search for -intitle:amazing intitle:catapult
    Then Catapult is the first search result
    And Amazing Catapult is not in the search results

  @filters
  Scenario: -intitle: doesn't highlight excluded title
    When I search for -intitle:catapult two words
    Then Two Words is the first search result
    And ffnonesenseword catapult pickles anotherword is the highlighted text of the first search result

  @wildcards @filters
  Scenario: intitle: can take a wildcard
    When I search for intitle:catapul*
    Then Catapult is the first search result

  @wildcards @setup_main
  Scenario: intitle: can take a wildcard and combine it with a regular wildcard
    When I search for intitle:catapul* amaz*
    Then Amazing Catapult is the first search result

  @filters
  Scenario: incategory: only includes pages with the category
    When I search for incategory:weaponry
    Then Catapult is in the search results
    And Amazing Catapult is in the search results
    But Two Words is not in the search results

  @filters
  Scenario: incategory: can be combined with other text
    When I search for incategory:weaponry amazing
    Then Amazing Catapult is the first search result

  @filters
  Scenario: -incategory: excludes pages with the category
    When I search for -incategory:weaponry incategory:twowords
    Then Two Words is the first search result

  @filters
  Scenario: incategory: works on categories from templates
    When I search for incategory:templatetagged incategory:twowords
    Then Two Words is the first search result

  @filters
  Scenario: incategory: when passed a quoted category that doesn't exist finds nothing even though there is a category that matches one of the words
    When I search for incategory:"Dontfindme Weaponry"
    Then there are no search results

  @filters
  Scenario: incategory when passed a single word category doesn't find a two word category that contains that word
    When I search for incategory:ASpace
    Then there are no search results

  @filters
  Scenario: incategory: finds a multiword category when it is surrounded by quotes
    When I search for incategory:"CategoryWith ASpace"
    Then IHaveATwoWordCategory is the first search result

  @filters
  Scenario Outline: Filters can be combined
    When I search for <term>
    Then <first_result> is the first search result
  Examples:
    |                  term                   | first_result |
    | incategory:twowords intitle:catapult    | none         |
    | incategory:twowords intitle:"Two Words" | Two Words    |
    | incategory:alpha incategory:beta        | AlphaBeta    |

  @filters
  Scenario Outline: Empty filters work like terms but aren't in test data so aren't found
    When I search for <term>
    Then there are no search results
  Examples:
    |         term           |
    | intitle:"" catapult    |
    | incategory:"" catapult |
    | intitle:               |
    | intitle:""             |
    | incategory:            |
    | incategory:""          |
