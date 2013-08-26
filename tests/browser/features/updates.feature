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

  Scenario: Pages weights are updated when new pages link to them 
    Given a page named WeightedLink%{epoch} 1 exists
    And a page named WeightedLink%{epoch} 2/1 exists with contents [[WeightedLink%{epoch} 2]]
    And a page named WeightedLink%{epoch} 2 exists
    And I search for WeightedLink%{epoch}
    And WeightedLink%{epoch} 2 is the first search result
    When a page named WeightedLink%{epoch} 1/1 exists with contents [[WeightedLink%{epoch} 1]]
    And a page named WeightedLink%{epoch} 1/2 exists with contents [[WeightedLink%{epoch} 1]]
    And I search for WeightedLink%{epoch}
    Then WeightedLink%{epoch} 1 is the first search result

  Scenario: Pages weights are updated when new pages link to their redirects
    Given a page named WeightedLinkRdir%{epoch} 1/Rdir exists with contents #REDIRECT [[WeightedLinkRdir%{epoch} 1]]
    And a page named WeightedLinkRdir%{epoch} 1 exists
    And a page named WeightedLinkRdir%{epoch} 2/Rdir exists with contents #REDIRECT [[WeightedLinkRdir%{epoch} 2]]
    And a page named WeightedLinkRdir%{epoch} 2/1 exists with contents [[WeightedLink%{epoch} 2/Rdir]]
    And a page named WeightedLinkRdir%{epoch} 2 exists
    And I search for WeightedLinkRdir%{epoch}
    And WeightedLinkRdir%{epoch} 2 is the first search result
    When a page named WeightedLinkRdir%{epoch} 1/1 exists with contents [[WeightedLinkRdir%{epoch} 1/Rdir]]
    And a page named WeightedLinkRdir%{epoch} 1/2 exists with contents [[WeightedLinkRdir%{epoch} 1/Rdir]]
    And I search for WeightedLinkRdir%{epoch}
    Then WeightedLinkRdir%{epoch} 1 is the first search result
