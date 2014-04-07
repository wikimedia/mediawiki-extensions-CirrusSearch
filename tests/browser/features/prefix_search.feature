@clean
Feature: Prefix search
  Background:
    Given I am at a random page

  @prefix
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
    | África                 | África                 | África                 |
    | Africa                 | África                 | África                 |
    | Template:Template Test | Template:Template Test | Template:Template Test |
    | l'or                   | L'Oréal                | Search results         |
    | l or                   | L'Oréal                | Search results         |
    | L'orea                 | L'Oréal                | Search results         |
    | L'Oréal                | L'Oréal                | L'Oréal                |
    | L’Oréal                | L'Oréal                | L'Oréal                |
    | L Oréal                | L'Oréal                | L'Oréal                |

  Scenario: Suggestions don't appear when you search for a string that is too long
    When I type 贵州省瞬时速度团头鲂身体c实施ysstsstsg说tyttxy以推销员会同香港推广系统在同他讨厌她团体淘汰赛系统大选于它拥有一天天用于与体育学院国ttxzyttxtxytdttyyyztdsytstsstxtttd天天体育系统的摄像头听到他他偷笑偷笑太阳团体杏眼桃腮他要tttxx y into the search box
    Then suggestions should not appear

  @prefix @redirect
  Scenario: Prefix search includes redirects
    When I type SEO Redirecttest into the search box
    Then suggestions should appear
    And SEO Redirecttest is the first suggestion
    When I click the search button
    Then I am on a page titled Search Engine Optimization Redirecttest

  @prefix @redirect
  Scenario: Prefix search includes redirects
    When I type Redirecttest Y into the search box
    Then suggestions should appear
    And Redirecttest Yay is the first suggestion
   And Redirecttest Yikes is not in the suggestions
