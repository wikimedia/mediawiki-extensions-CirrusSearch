@api @update
Feature: Search archive index
  Scenario: Deleted pages are added to archive index
    Given a page named DeleteMeTest exists
      And I api search for DeleteMeTest
     Then DeleteMeTest is the first api search result
     When I delete DeleteMeTest
      And I search deleted pages for deletemetest
     Then deleted page search returns DeleteMeTest as first result
