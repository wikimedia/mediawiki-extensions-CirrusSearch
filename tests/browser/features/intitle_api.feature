@clean @filters @intitle @api
Feature: Searches with the intitle filter
  Scenario: intitle: can be combined with other text
    When I api search for intitle:catapult amazing
    Then Amazing Catapult is the first api search result
      And Two Words is not in the api search results

  @wildcards
  Scenario: intitle: can take a wildcard
    When I api search for intitle:catapul*
    Then Catapult is in the api search results

  @wildcards @setup_main
  Scenario: intitle: can take a wildcard and combine it with a regular wildcard
    When I api search for intitle:catapul* amaz*
    Then Amazing Catapult is the first api search result

  Scenario: intitle: will accept a space after its : with quoted titles
    When I api search for intitle: "amazing catapult"
    Then Amazing Catapult is the first api search result
      And Two Words is not in the api search results
