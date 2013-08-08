Feature: Search backend updates
  Scenario: Deleted pages are removed from the index
    Given I am logged in
    And a page named DeleteMe exists with contents deleteme
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
    And a page named Template:ChangeMe exists with contents foo
    And a page named ChangeMyTemplate exists with contents {{Template:ChangeMe}}
    When I edit Template:ChangeMe to add superduperultrachangedme
    # Updating a template uses the job queue and that can take quite a while to complete in beta
    Then within 75 seconds searching for superduperultrachangedme yields ChangeMyTemplate as the first result
