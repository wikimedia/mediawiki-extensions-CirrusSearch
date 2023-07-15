@clean @acronym_fixer @api
Feature: Searches that involve acronyms
  Scenario Outline: Pages with acronyms are found by whole words
    When I api search for <term>
    Then PageWithAcronyms is in the api search results
  Examples:
    | term |
    | NASA |
    | nasa |
    | USSR.|
    | ussr.|
    | פצ   |

  Scenario Outline: Pages with whole words are found by acronyms
    When I api search for <term>
    Then PageWithAcronyms is in the api search results
  Examples:
    | term     |
    | I.B.M    |
    | i.b.m    |
    | A.S.A.P. |
    | a.s.a.p. |
    | մ.թ.ա.  |

  Scenario Outline: Multi-codepoint acronyms (combining marks and invisibles) find and are found
    When I api search for <term>
    Then PageWithAcronyms is in the api search results
  Examples:
    | term     |
    | সিওপিডি   |
    | អេភី      |
    | హెచ్ఐవీ   |
    | બી.બી.સી  |
    | ఎం.ఐ.టి. |

  Scenario Outline: Joined initials match with or without periods
    When I api search for <term>
    Then PageWithAcronyms is in the api search results
  Examples:
    | term |
    | JRR  |
    | ДРР  |
    | M.C. |
    | М.К. |
    | Ю́В   |
    | LM̂   |

  Scenario Outline: Acronyms are not mixed-up with hostnames or other unrelated tokens with a period in them
    When I api search for <term>
    Then PageWithAcronyms is not in the api search results
  Examples:
    | term         |
    | wikipediaorg |
    | legendary    |
    | phd          |
    | bsc          |
    | 1234         |
    | ABCD         |
