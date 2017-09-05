@clean
Feature: Elasticsearch version in Special:Version
  Scenario: Elasticsearch version is in Special:Version
    When I go to Special:Version
    Then there is a software version row for Elasticsearch