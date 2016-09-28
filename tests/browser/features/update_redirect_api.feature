@clean @api @redirect @update
Feature: Updating a page from or to a redirect
  @expect_failure
  Scenario: Turning a page into a redirect removes it from the search index
    Given a page named RedirectTarget exists
    When a page named ToBeRedirect%{epoch} exists
    Then within 20 seconds api searching for ToBeRedirect%{epoch} yields ToBeRedirect%{epoch} as the first result
    When a page named ToBeRedirect%{epoch} exists with contents #REDIRECT [[RedirectTarget]]
    Then within 20 seconds api searching for ToBeRedirect%{epoch} yields RedirectTarget as the first result
      And ToBeRedirect%{epoch} is not in the api search results

  Scenario: Turning a page from a redirect to a regular page puts it in the index
    Given a page named RedirectTarget exists
    When a page named StartsAsRedirect%{epoch} exists with contents #REDIRECT [[RedirectTarget]]
    Then within 20 seconds api searching for StartsAsRedirect%{epoch} yields RedirectTarget as the first result
    When a page named StartsAsRedirect%{epoch} exists
    Then within 40 seconds api searching for StartsAsRedirect%{epoch} yields StartsAsRedirect%{epoch} as the first result
      And RedirectTarget is not in the api search results
