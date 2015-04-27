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
