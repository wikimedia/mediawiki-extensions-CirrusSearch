@clean @phantomjs @phrase_prefix
Feature: Searches with a phrase prefix term
  Background:
    Given I am at a random page

  Scenario: Simple quoted prefix phrases get results
    When I search for "functional p*"
    Then Functional programming is the first search result
      And *Functional* *programming* is referential transparency. is the highlighted text of the first search result
