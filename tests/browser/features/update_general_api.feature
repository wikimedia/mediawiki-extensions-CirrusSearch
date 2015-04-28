@clean @api @update
Feature: Search backend updates
  Scenario: Deleted pages are removed from the index
    Given a page named DeleteMe exists
    Then within 20 seconds api searching for DeleteMe yields DeleteMe as the first result
    When I delete DeleteMe
    Then within 20 seconds api searching for DeleteMe yields none as the first result

  Scenario: Deleted redirects are removed from the index
    Given a page named DeleteMeRedirect exists with contents #REDIRECT [[DeleteMe]]
      And a page named DeleteMe exists
    Then within 20 seconds api searching for DeleteMeRedirect yields DeleteMe as the first result
    When I delete DeleteMeRedirect
    Then within 20 seconds api searching for DeleteMeRedirect yields none as the first result

  Scenario: Altered pages are updated in the index
    Given a page named ChangeMe exists with contents foo
    When I edit ChangeMe to add superduperchangedme
    Then within 20 seconds api searching for superduperchangedme yields ChangeMe as the first result

  Scenario: Pages containing altered template are updated in the index
    Given a page named Template:ChangeMe exists with contents foo
      And a page named ChangeMyTemplate exists with contents {{Template:ChangeMe}}
    When I edit Template:ChangeMe to add superduperultrachangedme
    Then within 20 seconds api searching for superduperultrachangedme yields ChangeMyTemplate as the first result

  Scenario: Really really long links don't break updates
    When a page named ReallyLongLink%{epoch} exists with contents @really_long_link.txt
    Then within 20 seconds api searching for ReallyLongLink%{epoch} yields ReallyLongLink%{epoch} as the first result
