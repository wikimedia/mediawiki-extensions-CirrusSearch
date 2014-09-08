@relevancy
Feature: Results are ordered from most relevant to least.
  Background:
    Given I am at a random page

  Scenario: Results are sorted based on what part of the page matches: title, redirect, category, etc
    When I search for Relevancytest
    And I disable incoming links in the weighting
    Then Relevancytest is the first search result
    And Relevancytestviaredirect is the second search result
    And Relevancytestviacategory is the third search result
    And Relevancytestviaheading is the fourth search result
    And Relevancytestviaopening is the fifth search result
    And Relevancytestviatext is the sixth or seventh search result
    And Relevancytestviaauxtext is the sixth or seventh search result

  Scenario: Results are sorted based on what part of the page matches: title, redirect, category, etc
    When I search for "Relevancytestphrase phrase"
    And I disable incoming links in the weighting
    Then Relevancytestphrase phrase is the first search result
    And Relevancytestphraseviaredirect is the second search result
    And Relevancytestphraseviacategory is the third search result
    And Relevancytestphraseviaheading is the fourth search result
    And Relevancytestphraseviaopening is the fifth search result
    And Relevancytestphraseviatext is the sixth or seventh search result
    And Relevancytestphraseviaauxtext is the sixth or seventh search result

  Scenario: Words in order are worth more then words out of order
    When I search for Relevancytwo Wordtest
    Then Relevancytwo Wordtest is the first search result
    And Wordtest Relevancytwo is the second search result

  Scenario: Results are sorted based on namespace: main, talk, file, help, file talk, etc
    When I search for all:Relevancynamespacetest
    Then Relevancynamespacetest is the first search result
    And Talk:Relevancynamespacetest is the second search result
    And File:Relevancynamespacetest is the third search result
    And Help:Relevancynamespacetest is the fourth search result
    And File talk:Relevancynamespacetest is the fifth search result
    And User talk:Relevancynamespacetest is the sixth search result
    And Template:Relevancynamespacetest is the seventh search result

  Scenario: When the user doesn't set a language are sorted with wiki language ahead of other languages
    When I search for Relevancylanguagetest
    Then Relevancylanguagetest/en is the first search result

  Scenario: When the user has a language results are sorted with user language ahead of wiki language ahead of other languages
    When I search for Relevancylanguagetest
    And I switch the language to ja
    Then Relevancylanguagetest/ja is the first search result
    And Relevancylanguagetest/en is the second search result
    And Relevancylanguagetest/ar is the third search result

  Scenario: Incoming links count in page weight
    When I search for Relevancylinktest -intitle:link
    Then Relevancylinktest Larger Extraword is the first search result
    And Relevancylinktest Smaller is the second search result
    When I disable incoming links in the weighting
    Then Relevancylinktest Smaller is the first search result
    And Relevancylinktest Larger Extraword is the second search result

  Scenario: Redirects count as incoming links
    When I search for Relevancyredirecttest
    Then Relevancyredirecttest Larger is the first search result
    And Relevancyredirecttest Smaller is the second search result