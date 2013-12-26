Feature: Go Search
  @go
  Scenario: I can "go" to a page with mixed capital and lower case name by the name all lower cased
    When I go search for mixedcapsandlowercase
    Then I am on a page titled MixedCapsAndLowerCase

  @go
  Scenario: I can "go" to a page with mixed capital and lower case name by the name with totally wrong case cased
    When I go search for miXEdcapsandlowercASe
    Then I am on a page titled MixedCapsAndLowerCase

  @go
  Scenario: I can "go" to a page with an accented character without the accent
    When I go search for africa
    Then I am on a page titled África

  @go @from_core
  Scenario: I can "go" to a page with mixed capital and lower case name by the name all lower cased and quoted
    When I go search for "mixedcapsandlowercase"
    Then I am on a page titled MixedCapsAndLowerCase

  @go @from_core
  Scenario: I can "go" to a user's page whether it is there or not
    When I go search for User:DoesntExist
    Then I am on a page titled User:DoesntExist
