@clean @go @phantomjs
Feature: Go Search
  @from_core
  Scenario: I can "go" to a user's page whether it is there or not
    When I go search for User:DoesntExist
    Then I am on a page titled User:DoesntExist

  @options
  Scenario Outline: When I near match more than one page but one is exact (case, modulo case, or converted to title case) I go to that page
    When I go search for <term> Nearmatchflattentest
    Then I am on a page titled <title> Nearmatchflattentest
  Examples:
    |      term      |      title      |
    | bach           | Johann Sebastian Bach |
    | Søn Redirectnoncompete | Blah Redirectnoncompete |
    | Soñ Redirectnoncompete | Blah Redirectnoncompete |
