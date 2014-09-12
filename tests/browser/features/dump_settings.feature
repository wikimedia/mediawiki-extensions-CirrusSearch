@clean @dump_settings @phantomjs
Feature: You can dump the settings CirrusSearch set on Elasticsearch's indexes
  Scenario: You can dump the settings CirrusSearch set on Elasticsearch's indexes
    When I dump the cirrus settings
    Then the page text contains near_space_flattener
      And the page text contains refresh_interval
