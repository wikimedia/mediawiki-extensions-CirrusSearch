@clean @phantomjs
Feature: Search backend updates that reference non-existant pages
  @non_existant
  Scenario: Pages that link to non-existant pages still get their search index updated
    Given a page named IDontExist doesn't exist
    And a page named ILinkToNonExistantPages%{epoch} exists with contents [[IDontExist]]
    When I am at a random page
    Then within 10 seconds searching for ILinkToNonExistantPages%{epoch} yields ILinkToNonExistantPages%{epoch} as the first result

  @non_existant
  Scenario: Pages that redirect to non-existant pages don't throw errors
    Given a page named IDontExist doesn't exist
    When a page named IRedirectToNonExistantPages%{epoch} exists with contents #REDIRECT [[IDontExist]]

  @non_existant
  Scenario: Linking to a non-existant page doesn't add it to the search index with an [INVALID] word count
    Given a page named ILinkToNonExistantPages%{epoch} exists with contents [[IDontExistLink%{epoch}]]
    When I am at a random page
    Then within 20 seconds searching for IDontExistLink%{epoch} yields ILinkToNonExistantPages%{epoch} as the first result
    And there are no search results with [INVALID] words in the data
    When a page named IDontExistLink%{epoch} exists
    Then within 10 seconds searching for IDontExistLink%{epoch} yields IDontExistLink%{epoch} as the first result
    And there are no search results with [INVALID] words in the data

  @non_existant
  Scenario: Redirecting to a non-existing page doesn't add it to the search index with an [INVALID] word count
    Given a page named IRedirectToNonExistantPages%{epoch} exists with contents #REDIRECT [[IDontExistRdir%{epoch}]]
    And I am at a random page
    When wait 5 seconds for the index to get the page
    And I search for IDontExistRdir%{epoch}
    And there are no search results with [INVALID] words in the data
    When a page named IDontExistRdir%{epoch} exists
    Then within 10 seconds searching for IDontExistRdir%{epoch} yields IDontExistRdir%{epoch} as the first result
    And there are no search results with [INVALID] words in the data

  @non_existant
  Scenario: Linking to a page that redirects to a non-existing page doesn't add it to the search index with an [INVALID] word count
    Given a page named IRedirectToNonExistantPagesLinked%{epoch} exists with contents #REDIRECT [[IDontExistRdirLinked%{epoch}]]
    And a page named ILinkIRedirectToNonExistantPages%{epoch} exists with contents [[IRedirectToNonExistantPagesLinked%{epoch}]]
    And I am at a random page
    When wait 5 seconds for the index to get the page
    And I search for IDontExistRdir%{epoch}
    And there are no search results with [INVALID] words in the data
    When a page named IDontExistRdirLinked%{epoch} exists
    Then within 10 seconds searching for IDontExistRdirLinked%{epoch} yields IDontExistRdirLinked%{epoch} as the first result
    And there are no search results with [INVALID] words in the data
