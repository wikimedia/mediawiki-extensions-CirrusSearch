@clean @phantomjs @file_text
Feature: Full text search
  Background:
    Given I am at the search results page

  @setup_main
  Scenario Outline: Searching for empty-string like values
    When I search for <term>
    Then I am on a page titled <title>
      And there are no search results
      And there are no errors reported
  Examples:
    | term                    | title          |
    | the empty string        | Search         |
    | %{exact: }              | Search results |
    | %{exact:      }         | Search results |
    | %{exact:              } | Search results |

  @javascript_injection
  Scenario: Searching for a page with javascript doesn't execute it (in this case, removing the page title)
    When I search for Javascript findme
    Then the title still exists

  Scenario: When you search for text that is in a file, you can find it!
    When I search for File:debian rhino
    Then File:Linux Distribution Timeline text version.pdf is the first search result and has an image link

  @js_and_css
  Scenario: JS pages don't corrupt the output
    When I search for User:Admin/some.js jQuery
    Then there is not alttitle on the first search result

  @js_and_css
  Scenario: CSS pages don't corrupt the output
    When I search for User:Admin/some.css jQuery
    Then there is not alttitle on the first search result

  @setup_main
  Scenario: Word count is output in the results
    When I search for Two Words
    Then there are search results with (4 words) in the data

  # Just take too long to run on a regular basis
  # @huge
  # Scenario: Searches for a huge phrase is reasonably fast (faster than the 40 second timeout)
  #   Given there are 3000 pages named Zeros%s with contents 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0
  #   When I search for 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0. 0.0.0.0.0.0.0.0.
  #   Then Zeros is in the first search result
