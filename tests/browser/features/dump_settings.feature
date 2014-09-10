@clean @phantomjs @dump_settings
Feature: You can dump the settings CirrusSearch set on Elasticsearch's indexes
  Scenario: You can dump the settings CirrusSearch set on Elasticsearch's indexes
    When I dump the cirrus settings
    Then the page text contains near_space_flattener
    Then the page text contains refresh_interval
