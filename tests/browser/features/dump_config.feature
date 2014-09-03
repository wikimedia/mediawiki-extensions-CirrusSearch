@clean @phantomjs @dump_config
Feature: You can dump CirrusSearch's configuration
  Scenario: You can dump CirrusSearch's configuration
    When I dump the cirrus config
    Then the page text contains phraseSuggestMaxErrors
    Then the page text contains namespaceWeights
    Then the page text does not contain Password
    Then the page text does not contain password
