@clean @dump_config @phantomjs
Feature: You can dump CirrusSearch's configuration
  Scenario: You can dump CirrusSearch's configuration
    When I dump the cirrus config
    Then the page text contains phraseSuggestMaxErrors
      And the page text contains namespaceWeights
      And the page text does not contain Password
      And the page text does not contain password
