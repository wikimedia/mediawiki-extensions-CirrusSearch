@clean @api @setup_main
Feature: Searches that contain fuzzy matches
  Scenario: Searching for <text>~0 activates fuzzy search but with 0 fuzziness (finding a result if the term is corret)
    When I api search for ffnonesenseword~0
    Then Two Words is the first api search result

  Scenario: Searching for <text>~0 activates fuzzy search but with 0 fuzziness (finding nothing if fuzzy search is required)
    When I api search for ffnonesensewor~0
    Then there are no api search results

  Scenario: Fuzzy search doesn't find terms that don't match the first two characters for performance reasons
    When I api search for fgnonesenseword~
    Then there are no api search results
