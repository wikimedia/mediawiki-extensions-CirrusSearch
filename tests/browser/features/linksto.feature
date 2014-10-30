@clean @filters @linksto @phantomjs
Feature: Searches with the linksto filter
  Background:
    Given I am at a random page

  Scenario: linksto only includes pages with the links
    When I search for linksto:"LinksToTest Target"
    Then LinksToTest Plain is in the search results
      And LinksToTest OtherText is in the search results
      But LinksToTest No Link is not in the search results
      And there is no link to create a new page from the search result

  Scenario: linksto can be combined with other text
    When I search for linksto:"LinksToTest Target" text
    Then LinksToTest OtherText is the first search result

  Scenario: -linksto excludes pages with the link
    When I search for -linksto:"LinksToTest Target" LinksToTest
    Then LinksToTest No Link is in the search results
      But LinksToTest Plain is not in the search results

  Scenario: linksto works on links from templates
    When I search for linksto:"LinksToTest Target" Using Template
    Then LinksToTest Using Template is the first search result

  Scenario: linksto finds links in non-main namespace
    When I search for linksto:"Template:LinksToTest Template"
    Then LinksToTest LinksToTemplate is the first search result
