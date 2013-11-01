Feature: Search backend updates
  Background:
    Given I am logged in
  Scenario: Deleted pages are removed from the index
    Given a page named DeleteMe exists with contents deleteme
    When I delete DeleteMe
    And I am at a random page so I can reload it if I need to
    # Sometimes deletes take a second or two to kick in
    And within 3 seconds typing deleteme into the search box yields none as the first suggestion

  Scenario: Altered pages are updated in the index
    Given a page named ChangeMe exists with contents foo
    When I edit ChangeMe to add superduperchangedme
    And I search for superduperchangedme
    Then ChangeMe is the first search result

  Scenario: Pages containing altered template are updated in the index
    Given a page named Template:ChangeMe exists with contents foo
    And a page named ChangeMyTemplate exists with contents {{Template:ChangeMe}}
    When I edit Template:ChangeMe to add superduperultrachangedme
    # Updating a template uses the job queue and that can take quite a while to complete in beta
    Then within 75 seconds searching for superduperultrachangedme yields ChangeMyTemplate as the first result

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

  @redirect_loop
  Scenario: Pages that redirect to themself don't throw errors
    When a page named IAmABad RedirectSelf%{epoch} exists with contents #REDIRECT [[IAmABad RedirectSelf%{epoch}]]
    Then I am on a page titled IAmABad RedirectSelf%{epoch}

  @redirect_loop
  Scenario: Pages that form a redirect chain don't throw errors
    When a page named IAmABad RedirectChain%{epoch} A exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} B]]
    And a page named IAmABad RedirectChain%{epoch} B exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} C]]
    And a page named IAmABad RedirectChain%{epoch} C exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} D]]
    And a page named IAmABad RedirectChain%{epoch} D exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} A]]
    Then I am on a page titled IAmABad RedirectChain%{epoch} D
    When a page named IAmABad RedirectChain%{epoch} B exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} D]]
    Then I am on a page titled IAmABad RedirectChain%{epoch} B

  Scenario: Pages weights are updated when new pages link to them
    Given a page named WeightedLink%{epoch} 1 exists
    And a page named WeightedLink%{epoch} 2/1 exists with contents [[WeightedLink%{epoch} 2]]
    And a page named WeightedLink%{epoch} 2 exists
    And I search for WeightedLink%{epoch}
    And WeightedLink%{epoch} 2 is the first search result
    When a page named WeightedLink%{epoch} 1/1 exists with contents [[WeightedLink%{epoch} 1]]
    And a page named WeightedLink%{epoch} 1/2 exists with contents [[WeightedLink%{epoch} 1]]
    Then within 75 seconds searching for WeightedLink%{epoch} yields WeightedLink%{epoch} 1 as the first result

  Scenario: Pages weights are updated when links are removed from them
    Given a page named WeightedLinkRemoveUpdate%{epoch} 1/1 exists with contents [[WeightedLinkRemoveUpdate%{epoch} 1]]
    And a page named WeightedLinkRemoveUpdate%{epoch} 1/2 exists with contents [[WeightedLinkRemoveUpdate%{epoch} 1]]
    And a page named WeightedLinkRemoveUpdate%{epoch} 1 exists
    And a page named WeightedLinkRemoveUpdate%{epoch} 2/1 exists with contents [[WeightedLinkRemoveUpdate%{epoch} 2]]
    And a page named WeightedLinkRemoveUpdate%{epoch} 2 exists
    And I search for WeightedLinkRemoveUpdate%{epoch}
    And WeightedLinkRemoveUpdate%{epoch} 1 is the first search result
    When a page named WeightedLinkRemoveUpdate%{epoch} 1/1 exists with contents [[Junk]]
    And a page named WeightedLinkRemoveUpdate%{epoch} 1/2 exists with contents [[Junk]]
    Then within 75 seconds searching for WeightedLinkRemoveUpdate%{epoch} yields WeightedLinkRemoveUpdate%{epoch} 2 as the first result

  Scenario: Pages weights are updated when new pages link to their redirects
    Given a page named WeightedLinkRdir%{epoch} 1/Redirect exists with contents #REDIRECT [[WeightedLinkRdir%{epoch} 1]]
    And a page named WeightedLinkRdir%{epoch} 1 exists
    And a page named WeightedLinkRdir%{epoch} 2/Redirect exists with contents #REDIRECT [[WeightedLinkRdir%{epoch} 2]]
    And a page named WeightedLinkRdir%{epoch} 2/1 exists with contents [[WeightedLinkRdir%{epoch} 2/Redirect]]
    And a page named WeightedLinkRdir%{epoch} 2 exists
    And I search for WeightedLinkRdir%{epoch}
    And WeightedLinkRdir%{epoch} 2 is the first search result
    When a page named WeightedLinkRdir%{epoch} 1/1 exists with contents [[WeightedLinkRdir%{epoch} 1/Redirect]]
    And a page named WeightedLinkRdir%{epoch} 1/2 exists with contents [[WeightedLinkRdir%{epoch} 1/Redirect]]
    Then within 75 seconds searching for WeightedLinkRdir%{epoch} yields WeightedLinkRdir%{epoch} 1 as the first result

  Scenario: Pages weights are updated when links are removed from their redirects
    Given a page named WLRURdir%{epoch} 1/1 exists with contents [[WLRURdir%{epoch} 1/Redirect]]
    And a page named WLRURdir%{epoch} 1/2 exists with contents [[WLRURdir%{epoch} 1/Redirect]]
    And a page named WLRURdir%{epoch} 1/Redirect exists with contents #REDIRECT [[WLRURdir%{epoch} 1]]
    And a page named WLRURdir%{epoch} 1 exists
    And a page named WLRURdir%{epoch} 2/Redirect exists with contents #REDIRECT [[WLRURdir%{epoch} 2]]
    And a page named WLRURdir%{epoch} 2/1 exists with contents [[WLRURdir%{epoch} 2/Redirect]]
    And a page named WLRURdir%{epoch} 2 exists
    And I search for WLRURdir%{epoch}
    And WLRURdir%{epoch} 1 is the first search result
    When a page named WLRURdir%{epoch} 1/1 exists with contents [[Junk]]
    And a page named WLRURdir%{epoch} 1/2 exists with contents [[Junk]]
    Then within 75 seconds searching for WLRURdir%{epoch} yields WLRURdir%{epoch} 2 as the first result

  Scenario: Redirects to redirects don't count in the score
    Given a page named WLDoubleRdir%{epoch} 1/Redirect exists with contents #REDIRECT [[WLDoubleRdir%{epoch} 1]]
    And a page named WLDoubleRdir%{epoch} 1/Redirect Redirect exists with contents #REDIRECT [[WLDoubleRdir%{epoch} 1/Redirect]]
    And a page named WLDoubleRdir%{epoch} 1/1 exists with contents [[WLDoubleRdir%{epoch} 1/Redirect Redirect]]
    And a page named WLDoubleRdir%{epoch} 1/2 exists with contents [[WLDoubleRdir%{epoch} 1/Redirect Redirect]]
    And a page named WLDoubleRdir%{epoch} 1 exists
    And a page named WLDoubleRdir%{epoch} 2/Redirect exists with contents #REDIRECT [[WLDoubleRdir%{epoch} 2]]
    And a page named WLDoubleRdir%{epoch} 2/1 exists with contents [[WLDoubleRdir%{epoch} 2/Redirect]]
    And a page named WLDoubleRdir%{epoch} 2/2 exists with contents [[WLDoubleRdir%{epoch} 2/Redirect]]
    And a page named WLDoubleRdir%{epoch} 2 exists
    When within 75 seconds searching for WLDoubleRdir%{epoch} yields WLDoubleRdir%{epoch} 2 as the first result
