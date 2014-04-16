@clean @phantomjs
Feature: Search backend updates
  Scenario: Deleted pages are removed from the index
    Given a page named DeleteMe exists
    And I am at a random page
    Then within 20 seconds searching for DeleteMe yields DeleteMe as the first result
    When I delete DeleteMe
    Then within 20 seconds searching for DeleteMe yields none as the first result

  Scenario: Altered pages are updated in the index
    Given a page named ChangeMe exists with contents foo
    When I edit ChangeMe to add superduperchangedme
    And I am at a random page
    Then within 20 seconds searching for superduperchangedme yields ChangeMe as the first result

  Scenario: Pages containing altered template are updated in the index
    Given a page named Template:ChangeMe exists with contents foo
    And a page named ChangeMyTemplate exists with contents {{Template:ChangeMe}}
    When I edit Template:ChangeMe to add superduperultrachangedme
    And I am at a random page
    Then within 20 seconds searching for superduperultrachangedme yields ChangeMyTemplate as the first result

  # This test doesn't rely on our paranoid revision delete handling logic, rather, it verifies what should work with the
  # logic with a similar degree of paranoia
  Scenario: When a revision is deleted the page is updated regardless of if the revision is current
    Given I am logged in 
    And a page named RevDelTest exists with contents first
    And a page named RevDelTest exists with contents delete this revision
    And within 20 seconds searching for intitle:RevDelTest "delete this revision" yields RevDelTest as the first result
    And a page named RevDelTest exists with contents current revision
    When I delete the second most recent revision of RevDelTest
    Then within 20 seconds searching for intitle:RevDelTest "delete this revision" yields none as the first result
    When I search for intitle:RevDelTest current revision
    Then RevDelTest is the first search result
