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

  Scenario: Pages that link to non-existant pages still get their search index updated
    Given a page named IDontExist doesn't exist
    And a page named ILinkToNonExistantPages%{epoch} exists with contents [[IDontExist]]
    When I search for ILinkToNonExistantPages%{epoch}
    Then ILinkToNonExistantPages%{epoch} is the first search result

  Scenario: Pages that redirect to non-existant pages don't throw errors
    Given a page named IDontExist doesn't exist
    When a page named IRedirectToNonExistantPages%{epoch} exists with contents #REDIRECT [[IDontExist]]
    Then I am on a page titled IRedirectToNonExistantPages%{epoch}

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
    When I search for WLDoubleRdir%{epoch}
    Then WLDoubleRdir%{epoch} 2 is the first search result
