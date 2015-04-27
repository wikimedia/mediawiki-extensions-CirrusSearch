@clean @filters @hastemplate @phantomjs
Feature: Searches with the hastemplate filter
  Background:
    Given I am at a random page

  Scenario: hastemplate: finds pages with matching templates (when you don't specify a namespace, Template is assumed)
    When I search for hastemplate:"Template Test"
    Then Two Words is the first search result
      And there is no link to create a new page from the search result
