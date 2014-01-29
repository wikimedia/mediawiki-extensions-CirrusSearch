Feature: Searches that contain fuzzy matches
  Background:
    Given I am at a random page

  @setup_main
  Scenario: Searching for <text>~ activates fuzzy search
    When I search for ffnonesensewor~
    Then Two Words is the first search result

  @setup_main
  Scenario Outline: Searching for <text>~<number between 0 and 1> activates fuzzy search
    When I search for ffnonesensewor~<number>
    Then Two Words is the first search result
  Examples:
    | number |
    | .8     |
    | 0.8    |
    | 1      |

  @setup_main
  Scenario: Searching for <text>~0 activates fuzzy search but with 0 fuzziness (finding a result if the term is corret)
    When I search for ffnonesenseword~0
    Then Two Words is the first search result

  @setup_main
  Scenario: Searching for <text>~0 activates fuzzy search but with 0 fuzziness (finding nothing if fuzzy search is required)
    When I search for ffnonesensewor~0
    Then there are no search results

  @setup_main
  Scenario: Fuzzy search doesn't find terms that don't match the first two characters for performance reasons
    When I search for fgnonesenseword~
    Then there are no search results
