@clean @phantomjs
Feature: Go Search
  @go
  Scenario: I can "go" to a page with mixed capital and lower case name by the name all lower cased
    When I go search for mixedcapsandlowercase
    Then I am on a page titled MixedCapsAndLowerCase

  @go
  Scenario: I can "go" to a page with mixed capital and lower case name by the name with totally wrong case cased
    When I go search for miXEdcapsandlowercASe
    Then I am on a page titled MixedCapsAndLowerCase

  @go
  Scenario: I can "go" to a page with an accented character without the accent
    When I go search for africa
    Then I am on a page titled África

  @go @from_core
  Scenario: I can "go" to a page with mixed capital and lower case name by the name all lower cased and quoted
    When I go search for "mixedcapsandlowercase"
    Then I am on a page titled MixedCapsAndLowerCase

  @go @from_core
  Scenario: I can "go" to a user's page whether it is there or not
    When I go search for User:DoesntExist
    Then I am on a page titled User:DoesntExist

  @go @options
  Scenario Outline: When I near match just one page I go to that page
    When I go search for <term> Nearmatchflattentest
    Then I am on a page titled <title> Nearmatchflattentest
  Examples:
    |      term      |      title      |
    | soñ onlyaccent | Soñ Onlyaccent  |
    | son onlyaccent | Soñ Onlyaccent  |

  @go @options
  Scenario Outline: When I near match more than one page but one is exact (case, modulo case, or converted to title case) I go to that page
    When I go search for <term> Nearmatchflattentest
    Then I am on a page titled <title> Nearmatchflattentest
  Examples:
    |      term      |      title      |
    | son            | son             |
    | Son            | Son             |
    | SON            | SON             |
    | soñ            | soñ             |
    | Son Nolower    | Son Nolower     |
    | son Nolower    | Son Nolower     |
    | SON Nolower    | SON Nolower     |
    | soñ Nolower    | Soñ Nolower     |
    | son Titlecase  | Son Titlecase   |
    | Son Titlecase  | Son Titlecase   |
    | soñ Titlecase  | Soñ Titlecase   |
    | SON Titlecase  | Son Titlecase   |
    | soñ twoaccents | Soñ Twoaccents  |
    | són twoaccents | Són Twoaccents  |
    | bach           | Johann Sebastian Bach |

  @go @options
  Scenario Outline: When I near match more than one page but none of them are exact then I go to the search results page
    When I go search for <term> Nearmatchflattentest
    Then I am on a page titled Search results
  Examples:
    |       term      |
    | son twoaccents  |
    | Son Double      |

  @go @redirect
  Scenario: When I near match a redirect and a page then the redirect is chosen if it is a better match
    When I go search for SEO Redirecttest
    Then I am on a page titled Search Engine Optimization Redirecttest
