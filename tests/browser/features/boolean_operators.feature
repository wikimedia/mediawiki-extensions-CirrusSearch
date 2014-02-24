 @clean
Feature: Searches with boolean operators
  Background:
    Given I am at a random page

  @boolean_operators @setup_main
  Scenario Outline: -, !, and NOT prohibit words in search results
    When I search for <query>
    Then Catapult is the first search result
    But Amazing Catapult is not in the search results
    And there is no link to create a new page from the search result
  Examples:
  |        query         |
  | catapult -amazing    |
  | -amazing catapult    |
  | catapult !amazing    |
  | !amazing catapult    |
  | catapult NOT amazing |
  | NOT amazing catapult |

  @boolean_operators @setup_main
  Scenario Outline: +, &&, and AND require matches but since that is the default they don't look like they do anything
    When I search for <query>
    Then Amazing Catapult is the first search result
    But Catapult is not in the search results
    And there is no link to create a new page from the search result
  Examples:
  |         query         |
  | +catapult amazing     |
  | amazing +catapult     |
  | +amazing +catapult    |
  | catapult AND amazing  |

  @boolean_operators @setup_main
  Scenario Outline: OR and || matches docs with either set
    When I search for <query>
    Then Catapult is in the search results
    And Two Words is in the search results
    And there is no link to create a new page from the search result
  Examples:
  |          query         |
  | catapult OR África     |
  | África \|\| catapult   |
  | catapult OR "África"   |
  | catapult \|\| "África" |
  | "África" OR catapult   |
  | "África" \|\| catapult |
