@clean
Feature: Search backend updates that reference non-existant pages
  Background:
    Given I am logged in

  @non_existant
  Scenario: Pages that link to non-existant pages still get their search index updated
    Given a page named IDontExist doesn't exist
    And a page named ILinkToNonExistantPages%{epoch} exists with contents [[IDontExist]]
    When I search for ILinkToNonExistantPages%{epoch}
    Then ILinkToNonExistantPages%{epoch} is the first search result

  @non_existant
  Scenario: Pages that redirect to non-existant pages don't throw errors
    Given a page named IDontExist doesn't exist
    When a page named IRedirectToNonExistantPages%{epoch} exists with contents #REDIRECT [[IDontExist]]
    Then I am on a page titled IRedirectToNonExistantPages%{epoch}

  @non_existant
  Scenario: Linking to a non-existant page doesn't add it to the search index with an [INVALID] word count
    Given a page named IDontExistLink%{epoch} doesn't exist
    And a page named ILinkToNonExistantPages%{epoch} exists with contents [[IDontExistLink%{epoch}]]
    When I search for IDontExistLink%{epoch}
    Then there are no search results with [INVALID] words in the data
    When a page named IDontExistLink%{epoch} exists
    And I search for IDontExistLink%{epoch}
    Then IDontExistLink%{epoch} is the first search result
    And there are no search results with [INVALID] words in the data

  @non_existant
  Scenario: Redirecting to a non-existing page doesn't add it to the search index with an [INVALID] word count
    Given a page named IDontExistRdir%{epoch} doesn't exist
    And a page named IRedirectToNonExistantPages%{epoch} exists with contents #REDIRECT [[IDontExistRdir%{epoch}]]
    When I search for IDontExistRdir%{epoch}
    Then there are no search results with [INVALID] words in the data
    When a page named IDontExistRdir%{epoch} exists
    And I search for IDontExistRdir%{epoch}
    Then IDontExistRdir%{epoch} is the first search result
    And there are no search results with [INVALID] words in the data

  @non_existant
  Scenario: Linking to a page that redirects to a non-existing page doesn't add it to the search index with an [INVALID] word count
    Given a page named IDontExistRdirLinked%{epoch} doesn't exist
    And a page named IRedirectToNonExistantPagesLinked%{epoch} exists with contents #REDIRECT [[IDontExistRdirLinked%{epoch}]]
    And a page named ILinkIRedirectToNonExistantPages%{epoch} exists with contents [[IRedirectToNonExistantPagesLinked%{epoch}]]
    When I search for IDontExistRdirLinked%{epoch}
    Then there are no search results with [INVALID] words in the data
    When a page named IDontExistRdirLinked%{epoch} exists
    And I search for IDontExistRdirLinked%{epoch}
    Then IDontExistRdirLinked%{epoch} is the first search result
    And there are no search results with [INVALID] words in the data
