@clean @exact_quotes @phantomjs
Feature: Searches that contain quotes
  Background:
    Given I am at a random page

  @setup_main
  Scenario: Searching for a word in quotes disbles stemming (can't find plural with singular)
    When I search for "pickle"
    Then there are no search results
      And there is no link to create a new page from the search result

  Scenario: Searching for a word in quotes disbles stemming (can still find plural with exact match)
    When I search for "pickles"
    Then Two Words is the first search result

  Scenario: Searching for a phrase in quotes disbles stemming (can't find plural with singular)
    When I search for "catapult pickle"
    Then there are no search results

  Scenario: Searching for a phrase in quotes disbles stemming (can still find plural with exact match)
    When I search for "catapult pickles"
    Then Two Words is the first search result

  Scenario: Quoted phrases have a default slop of 0
    When I search for "ffnonesenseword pickles"
    Then none is the first search result
    When I search for "ffnonesenseword pickles"~1
    Then Two Words is the first search result

  Scenario: Quoted phrases match stop words
    When I search for "Contains A Stop Word"
    Then Contains A Stop Word is the first search result

  Scenario: Adding a ~ to a phrase keeps stemming enabled
    When I search for "catapult pickle"~
    Then Two Words is the first search result

  Scenario: Adding a ~ to a phrase switches the default slop to 0
    When I search for "ffnonesenseword pickle"~
    Then none is the first search result
    When I search for "ffnonesenseword pickle"~1~
    Then Two Words is the first search result

  Scenario: Adding a ~ to a phrase stops it from matching stop words so long as there is enough slop
    When I search for "doesn't actually Contain A Stop Words"~1~
    Then Doesn't Actually Contain Stop Words is the first search result

  Scenario: Adding a ~<a number>~ to a phrase keeps stemming enabled
    When I search for "catapult pickle"~0~
    Then Two Words is the first search result

  Scenario: Adding a ~<a number> to a phrase turns off because it is a proximity search
    When I search for "catapult pickle"~0
    Then there are no search results

  Scenario: Searching for a quoted * actually searches for a *
    When I search for "pick*"
    Then Pick* is the first search result

  Scenario Outline: Searching for "<word> <word>"~<number> activates a proximity search
    When I search for "ffnonesenseword anotherword"~<proximity>
    Then <result> is the first search result
  Examples:
    | proximity | result    |
    | 0         | none      |
    | 1         | none      |
    | 2         | Two Words |
    | 3         | Two Words |
    | 77        | Two Words |

  Scenario Outline: Prefixing a quoted phrase with - or ! or NOT negates it
    When I search for catapult <negation>"two words"<suffix>
    Then Catapult is in the search results
      And Two Words is not in the search results
  Examples:
    |    negation    | suffix |
    | -              |        |
    | !              |        |
    | %{exact:NOT }  |        |
    | -              | ~      |
    | !              | ~      |
    | %{exact:NOT }  | ~      |
    | -              | ~1     |
    | !              | ~1     |
    | %{exact:NOT }  | ~1     |
    | -              | ~7~    |
    | !              | ~7~    |
    | %{exact:NOT }  | ~7~    |

  Scenario: Can combine positive and negative phrase search
    When I search for catapult "catapult" -"two words" -"some stuff"
    Then Catapult is in the search results
      And Two Words is not in the search results

  Scenario: Can combine positive and negative phrase search (backwards)
    When I search for catapult -"asdf" "two words"
    Then Two Words is in the search results
      And Catapult is not in the search results
