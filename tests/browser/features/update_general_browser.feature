@clean @phantomjs @update
Feature: Search backend updates
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

  @move
  Scenario: Moved pages that leave a redirect are updated in the index
    Given I am logged in
      And a page named Move%{epoch} From2 exists with contents move me
      And within 20 seconds searching for Move%{epoch} From2 yields Move%{epoch} From2 as the first result
    When I move Move%{epoch} From2 to Move%{epoch} To2 and do not leave a redirect
    Then within 20 seconds searching for Move%{epoch} From2 yields none as the first result
      And within 20 seconds searching for Move%{epoch} To2 yields Move%{epoch} To2 as the first result

  @move
  Scenario: Moved pages that switch indexes are removed from their old index if they leave a redirect
    Given I am logged in
      And a page named Move%{epoch} From3 exists with contents move me
      And within 20 seconds searching for Move%{epoch} From3 yields Move%{epoch} From3 as the first result
    When I move Move%{epoch} From3 to User:Move%{epoch} To3 and leave a redirect
    Then within 20 seconds searching for User:Move%{epoch} To3 yields User:Move%{epoch} To3 as the first result
      And within 20 seconds searching for Move%{epoch} From3 yields none as the first result

  @move
  Scenario: Moved pages that switch indexes are removed from their old index if they don't leave a redirect
    Given I am logged in
      And a page named Move%{epoch} From4 exists with contents move me
      And within 20 seconds searching for Move%{epoch} From4 yields Move%{epoch} From4 as the first result
    When I move Move%{epoch} From4 to User:Move%{epoch} To4 and do not leave a redirect
    Then within 20 seconds searching for User:Move%{epoch} To4 yields User:Move%{epoch} To4 as the first result
      And within 20 seconds searching for Move%{epoch} To4 yields none as the first result
