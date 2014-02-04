Feature: Searches with the hastemplate filter
  Background:
    Given I am at a random page

  @filters @hastemplate
  Scenario: hastemplate: finds pages with matching templates (when you don't specify a namespace, Template is assumed)
    When I search for hastemplate:"Template Test"
    Then Two Words is the first search result
    And there is no link to create a new page from the search result

  @filters @hastemplate
  Scenario: hastemplate: finds pages with matching templates with namespace specified
    When I search for hastemplate:"Template:Template Test"
    Then Two Words is the first search result

  @filters @hastemplate
  Scenario: hastemplate: finds pages with matching templates that aren't in the template namespace if you prefix them with the namespace
    When I search for hastemplate:"Talk:TalkTemplate"
    Then HasTTemplate is the first search result

  @filters @hastemplate
  Scenario: hastemplate: finds pages which contain a template in the main namespace if they are prefixed with : (which is how you'd transclude them)
    When I search for hastemplate::MainNamespaceTemplate
    Then HasMainNSTemplate is the first search result

  @filters @hastemplate
  Scenario: hastemplate: doesn't find pages which contain a template in the main namespace if you don't prefix the name with : (that is for the Template namespace)
    When I search for hastemplate:MainNamespaceTemplate
    Then HasMainNSTemplate is not in the search results

  @filters @hastemplate
  Scenario: -hastemplate removes pages with matching templates
    When I search for -hastemplate:"Template Test" catapult
    Then Two Words is not in the search results
