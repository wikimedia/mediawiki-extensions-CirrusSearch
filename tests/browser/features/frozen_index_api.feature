@frozen
Feature: Mutations to frozen indexes are properly delayed
  Scenario: Updates to frozen indexes are delayed
   Given I delete FrozenTest
     And a page named FrozenTest exists with contents foobarbaz
     And within 20 seconds api searching for foobarbaz yields FrozenTest as the first result
     And I globally freeze indexing
     And a page named FrozenTest exists with contents superduperfrozen
     And I wait 10 seconds
     And I api search for superduperfrozen
     And FrozenTest is not in the api search results
    When I globally thaw indexing
     And I wait 10 seconds
    Then I api search for superduperfrozen yields FrozenTest as the first result

  Scenario: Deletes to frozen indexes are delayed
   Given a page named FrozenDeleteTest exists with contents bazbarfoo
     And within 20 seconds api searching for bazbarfoo yields FrozenDeleteTest as the first result
     And I globally freeze indexing
     And I delete FrozenDeleteTest
     And a page named FrozenDeleteTest exists with contents mrfreeze recreated this page to work around mediawiki's behavior of not showing deleted pages in search results.  mrfreeze is surprisingly helpful.
     And I wait 10 seconds
     And I api search for bazbarfoo
     And FrozenDeleteTest is the first api search result
    When I globally thaw indexing
     And I wait 10 seconds
    Then I api search for bazbarfoo yields no results

  @commons
  Scenario: Updates to OtherIndex are delayed
   Given I delete on commons File:Frozen.svg
      And I delete File:Frozen.svg
      And a file named File:Frozen.svg exists on commons with contents Frozen.svg and description File stored on commons and locally for frozen tests
      And a file named File:Frozen.svg exists with contents Frozen.svg and description Locally stored file also on commons for frozen tests

     And within 20 seconds api searching in namespace 6 for frozen yields Locally stored file also on commons for *frozen* tests as the highlighted snippet of the first api search result
     And I globally freeze indexing
     And I delete File:Frozen.svg
     And a file named File:Frozen.svg exists with contents Frozen.svg and description frozen reupload of locally stored file
     And I wait 10 seconds
     And I api search in namespace 6 for frozen
     And Locally stored file also on commons for *frozen* tests is the highlighted snippet of the first api search result
    When I globally thaw indexing
     And I wait 10 seconds
    Then I api search in namespace 6 for frozen yields *frozen* reupload of locally stored file as the highlighted snippet of the first api search result
