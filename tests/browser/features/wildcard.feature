@clean @phantomjs @wildcard
Feature: Searches that contain wildcard matches
  Background:
    Given I am at a random page

  Scenario Outline: Searching with a single wildcard finds expected results
    When I search for catapu<wildcard>
    Then Catapult is the first search result
      And there is no link to create a new page from the search result
  Examples:
    | wildcard |
    | *        |
    | ?t       |
    | l?       |

  Scenario Outline: Wildcards match plain matches
    When I search for pi<wildcard>les
    Then Two Words is the first search result
  Examples:
    | wildcard |
    | *        |
    | ?k       |
    | c?       |

  Scenario Outline: Wildcards don't match stemmed matches
    When I search for pi<wildcard>kle
    Then there are no search results
  Examples:
    | wildcard |
    | *        |
    | ?k       |

  Scenario Outline: Wildcards in leading intitle: terms match
    When I search for intitle:functiona<wildcard> intitle:programming
    Then Functional programming is the first search result
  Examples:
    | wildcard |
    | *        |
    | ?        |

  Scenario Outline: Wildcard suffixes in trailing intitle: terms match stemmed matches
    When I search for intitle:functional intitle:programmin<wildcard>
    Then Functional programming is the first search result
  Examples:
    | wildcard |
    | *        |
    | ?        |

  Scenario Outline: Wildcards within trailing intitle: terms match stemmed matches
    When I search for intitle:functional intitle:prog<wildcard>amming
    Then Functional programming is the first search result
  Examples:
    | wildcard |
    | *        |
    | ?        |
