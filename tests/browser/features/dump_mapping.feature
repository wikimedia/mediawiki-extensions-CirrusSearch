@clean @phantomjs @dump_mapping
Feature: You can dump the mapping CirrusSearch set on Elasticsearch's indexes
  Scenario: You can dump the mapping CirrusSearch set on Elasticsearch's indexes
    When I dump the cirrus mapping
    Then the page text contains "_all":{"enabled":false}
