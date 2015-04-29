@clean @api @wildcard
Feature: Searches that contain wildcard matches
  Scenario Outline: Wildcards match plain matches
    When I api search for pi<wildcard>les
    Then Two Words is the first api search result
  Examples:
    | wildcard |
    | *        |
    | ?k       |
    | c?       |

  Scenario Outline: Wildcards don't match stemmed matches
    When I api search for pi<wildcard>kle
    Then there are no api search results
  Examples:
    | wildcard |
    | *        |
    | ?k       |

  Scenario Outline: Wildcards in leading intitle: terms match
    When I api search for intitle:functiona<wildcard> intitle:programming
    Then Functional programming is the first api search result
  Examples:
    | wildcard |
    | *        |
    | ?        |

  Scenario Outline: Wildcard suffixes in trailing intitle: terms match stemmed matches
    When I api search for intitle:functional intitle:programmin<wildcard>
    Then Functional programming is the first api search result
  Examples:
    | wildcard |
    | *        |
    | ?        |

  Scenario Outline: Wildcards within trailing intitle: terms match stemmed matches
    When I api search for intitle:functional intitle:prog<wildcard>amming
    Then Functional programming is the first api search result
  Examples:
    | wildcard |
    | *        |
    | ?        |

  Scenario Outline: Searching with a single wildcard finds expected results
    When I api search for catapu<wildcard>
    Then Catapult is the first api search result
  Examples:
    | wildcard |
    | *        |
    | ?t       |
    | l?       |
