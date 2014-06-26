@clean @phantomjs
Feature: Search backend updates
  Scenario: Deleted pages are removed from the index
    Given a page named DeleteMe exists
    And I am at a random page
    Then within 20 seconds searching for DeleteMe yields DeleteMe as the first result
    When I delete DeleteMe
    Then within 20 seconds searching for DeleteMe yields none as the first result

  Scenario: Deleted redirects are removed from the index
    Given a page named DeleteMeRedirect exists with contents #REDIRECT [[DeleteMe]]
    And a page named DeleteMe exists
    And I am at a random page
    Then within 20 seconds searching for DeleteMeRedirect yields DeleteMe as the first result
    When I delete DeleteMeRedirect
    Then within 20 seconds searching for DeleteMeRedirect yields none as the first result

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

  @Move
  Scenario: Moved pages that leave a redirect are updated in the index
    Given I am logged in
    And a page named Move%{epoch} From1 exists with contents move me
    And within 20 seconds searching for Move%{epoch} From1 yields Move%{epoch} From1 as the first result
    When I move Move%{epoch} From1 to Move%{epoch} To1 and leave a redirect
    And within 20 seconds searching for Move%{epoch} From1 yields Move%{epoch} To1 as the first result
    And within 20 seconds searching for Move%{epoch} To1 yields Move%{epoch} To1 as the first result

  @Move
  Scenario: Moved pages that leave a redirect are updated in the index
    Given I am logged in
    And a page named Move%{epoch} From2 exists with contents move me
    And within 20 seconds searching for Move%{epoch} From2 yields Move%{epoch} From2 as the first result
    When I move Move%{epoch} From2 to Move%{epoch} To2 and do not leave a redirect
    Then within 20 seconds searching for Move%{epoch} From2 yields none as the first result
    And within 20 seconds searching for Move%{epoch} To2 yields Move%{epoch} To2 as the first result

  @Move
  Scenario: Moved pages that switch indexes are removed from their old index if they leave a redirect
    Given I am logged in
    And a page named Move%{epoch} From3 exists with contents move me
    And within 20 seconds searching for Move%{epoch} From3 yields Move%{epoch} From3 as the first result
    When I move Move%{epoch} From3 to User:Move%{epoch} To3 and leave a redirect
    Then within 20 seconds searching for User:Move%{epoch} To3 yields User:Move%{epoch} To3 as the first result
    And within 20 seconds searching for Move%{epoch} From3 yields none as the first result

  @Move
  Scenario: Moved pages that switch indexes are removed from their old index if they don't leave a redirect
    Given I am logged in
    And a page named Move%{epoch} From4 exists with contents move me
    And within 20 seconds searching for Move%{epoch} From4 yields Move%{epoch} From4 as the first result
    When I move Move%{epoch} From4 to User:Move%{epoch} To4 and do not leave a redirect
    Then within 20 seconds searching for User:Move%{epoch} To4 yields User:Move%{epoch} To4 as the first result
    And within 20 seconds searching for Move%{epoch} To4 yields none as the first result

  Scenario: Really really long links don't break updates
    Given I am logged in
    And a page named ReallyLongLink%{epoch} exists with contents @really_long_link.txt
    And within 20 seconds searching for ReallyLongLink%{epoch} yields ReallyLongLink%{epoch} as the first result
