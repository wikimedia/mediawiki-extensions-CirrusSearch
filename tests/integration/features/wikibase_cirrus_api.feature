@wbcs
Feature: API Search with WikibaseCirrusSearch
  Scenario: Items can be found in full text search by their labels
    When I api search for entities in namespace 120 on wikidata for universe
    Then Universe is the first api entity search result

  Scenario: Items can be found in autocomplete by their labels
    When I wbsearchentities on wikidata for uni
    Then Universe is the label of the first wbsearchentities result

