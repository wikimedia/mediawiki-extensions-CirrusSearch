@clean @phantomjs @special_random
Feature: Cirrus powered Special:Random
  Scenario: Special:Random gives a page in the main namespace by default
    When I am at a random page
    Then I am on a page in the main namespace

  # Repeats test three times because failure is, well, random
  Scenario: Special:Random/User gives a page in the user namespace
    When I am at a random User page
    Then I am on a page in the User namespace
    When I am at a random User page
    Then I am on a page in the User namespace
    When I am at a random User page
    Then I am on a page in the User namespace

  # Repeats test three times because failure is, well, random
  Scenario: Special:Random/User_talk gives a page in the user namespace
    When I am at a random User_talk page
    Then I am on a page in the User talk namespace
    When I am at a random User_talk page
    Then I am on a page in the User talk namespace
    When I am at a random User_talk page
    Then I am on a page in the User talk namespace
