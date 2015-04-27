@clean @filters @linksto @api
Feature: Searches with the linksto filter
  Scenario: linksto can be combined with other text
    When I api search for linksto:"LinksToTest Target" text
    Then LinksToTest OtherText is the first api search result

  Scenario: -linksto excludes pages with the link
    When I api search for -linksto:"LinksToTest Target" LinksToTest
    Then LinksToTest No Link is in the api search results
      But LinksToTest Plain is not in the api search results

  Scenario: linksto works on links from templates
    When I api search for linksto:"LinksToTest Target" Using Template
    Then LinksToTest Using Template is the first api search result

  Scenario: linksto finds links in non-main namespace
    When I api search for linksto:"Template:LinksToTest Template"
    Then LinksToTest LinksToTemplate is the first api search result
