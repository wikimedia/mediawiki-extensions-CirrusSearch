@clean @phantomjs @prefix
Feature: Prefix search
  Background:
    Given I am at a random page

  Scenario Outline: Search suggestions
    When I type <term> into the search box
    Then suggestions should appear
      And <first_result> is the first suggestion
      And I should be offered to search for <term>
    When I click the search button
      Then I am on a page titled <title>
  Examples:
    | term                   | first_result           | title                  |
# Note that there are more links to catapult then to any other page that starts with the
# word "catapult" so it should be first
    | catapult               | Catapult               | Catapult               |
    | catapul                | Catapult               | Search results         |
    | two words              | Two Words              | Two Words              |
    | ~catapult              | none                   | Search results         |
    | Template:Template Test | Template:Template Test | Template:Template Test |
    | l'or                   | L'Oréal                | Search results         |
    | l or                   | L'Oréal                | Search results         |
    | L'orea                 | L'Oréal                | Search results         |
    | L'Oréal                | L'Oréal                | L'Oréal                |
    | L’Oréal                | L'Oréal                | L'Oréal                |
    | L Oréal                | L'Oréal                | L'Oréal                |
    | Jean-Yves Le Drian     | Jean-Yves Le Drian     | Jean-Yves Le Drian     |
    | Jean Yves Le Drian     | Jean-Yves Le Drian     | Jean-Yves Le Drian     |

  @redirect
  Scenario: Prefix search includes redirects
    When I type SEO Redirecttest into the search box
    Then suggestions should appear
      And SEO Redirecttest is the first suggestion
    When I click the search button
    Then I am on a page titled Search Engine Optimization Redirecttest

  @redirect
  Scenario: Prefix search includes redirects for pages outside the main namespace
    When I type User_talk:SEO Redirecttest into the search box
    Then suggestions should appear
      And User talk:SEO Redirecttest is the first suggestion
    When I click the search button
    Then I am on a page titled User talk:Search Engine Optimization Redirecttest

  @accent_squashing @accented_namespace
  Scenario Outline: Search suggestions with accents
    When I type <term> into the search box
    Then suggestions should appear
      And <first_result> is the first suggestion
      And I should be offered to search for <term>
    When I click the search button
    Then I am on a page titled <title>
  Examples:
    | term                   | first_result           | title                  |
    | África                 | África                 | África                 |
    | Africa                 | África                 | África                 |
    | AlphaBeta              | AlphaBeta              | AlphaBeta              |
    | ÁlphaBeta              | AlphaBeta              | AlphaBeta              |
    | Mó:Test                | Mó:Test                | Mó:Test                |
    | Mo:Test                | Mó:Test                | Mó:Test                |
    | file:Mo:Test           | none                   | Search results         |

  # Just take too long to run on a regular basis
  # @redirect @huge
  # Scenario: Prefix search on pages with tons of redirects is reasonably fast
  #   Given a page named IHaveTonsOfRedirects exists
  #     And there are 1000 redirects to IHaveTonsOfRedirects of the form TonsOfRedirects%s
  #   When I type TonsOfRedirects into the search box
  #   Then suggestions should appear
