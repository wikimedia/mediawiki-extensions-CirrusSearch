@clean @phantomjs @bad_syntax
Feature: Searches with syntax errors
  Background:
    Given I am at a random page

  @setup_main
  Scenario: Searching for <text>~<text> treats the tilde like a space (finding a result if the term is correct)
    When I search for ffnonesenseword~pickles
    Then there is no warning
    And Two Words is the first search result
    And there is a link to create a new page from the search result

  @setup_main
  Scenario: Searching for <text>~<text> treats the tilde like a space (not finding any results if a fuzzy search was needed)
    When I search for ffnonesensewor~pickles
    Then there is no warning
    And there are no search results
    And there is a link to create a new page from the search result

  @exact_quotes @setup_main
  Scenario: Searching for "<word> <word>"~<not a numer> treats the ~ as a space
    When I search for "ffnonesenseword catapult"~anotherword
    Then there is no warning
    And Two Words is the first search result
    And there is no link to create a new page from the search result

  @balance_quotes
  Scenario Outline: Searching for for a phrase with a hanging quote adds the quote automatically
    When I search for <term>
    Then there is no warning
    And Two Words is the first search result
    And there is no link to create a new page from the search result
   Examples:
    |                      term                     |
    | "two words                                    |
    | "two words" "ffnonesenseword catapult         |
    | "two words" "ffnonesenseword catapult pickles |
    | "two words" pickles "ffnonesenseword catapult |

  @balance_quotes
  Scenario Outline: Searching for a phrase containing /, :, and \" find the page as expected
    Given a page named <title> exists
    When I search for <term>
    Then there is no warning
    And <title> is the first search result
    And there is no link to create a new page from the search result
  Examples:
    |                        term                       |                   title                   |
    | "10.1093/acprof:oso/9780195314250.003.0001"       | 10.1093/acprof:oso/9780195314250.003.0001 |
    | "10.5194/os-8-1071-2012"                          | 10.5194/os-8-1071-2012                    |
    | "10.7227/rie.86.2"                                | 10.7227/rie.86.2                          |
    | "10.7227\"yay"                                    | 10.7227"yay                               |
    | intitle:"1911 Encyclopædia Britannica/Dionysius"' | 1911 Encyclopædia Britannica/Dionysius    |

  @boolean_operators
  Scenario Outline: boolean operators in bad positions in the query are ignored so you get the option to create a new page
    When I search for <query>
    Then there is no warning
    And Catapult is in the first search result
    And there is a link to create a new page from the search result
  Examples:
  |         query          |
  | catapult +             |
  | catapult -             |
  | catapult !             |
  # Bug 60362
  #| catapult AND           |
  #| catapult OR            |
  #| catapult NOT           |
  | + catapult             |
  | - catapult             |
  | ! catapult             |
  # Bug 60362
  #| AND catapult           |
  #| OR catapult            |
  | catapult + amazing     |
  | catapult - amazing     |
  | catapult ! amazing     |
  | amazing+catapult       |
  | amazing-catapult       |
  | amazing!catapult       |
  | catapult!!!!!!!        |
  | catapult !!!!!!!!      |
  | !!!! catapult          |
  | ------- catapult       |
  | ++++ catapult ++++     |
  | ++catapult++++catapult |
  | catapult ~/            |
  | catapult ~/            |
  | catapult~◆~catapult    |
  | ******* catapult       |


  @boolean_operators
  Scenario Outline: boolean operators in bad positions in the query are ignored but if there are other valid operators then you don't get the option to create a new page
    When I search for <query>
    Then there is no warning
    And Catapult is in the first search result
    And there is no link to create a new page from the search result
  Examples:
  |         query          |
  | catapult AND + amazing |
  | catapult AND - amazing |
  | catapult AND ! amazing |
  | catapult \|\|---       |
  | catapult~~~~....[[\|\|.\|\|\|\|\|\|+\|+\|=\\\\=\\*.$.$.$. |
  | T:8~=~¥9:77:7:57;7;76;6346- OR catapult |
  | catapult OR T:8~=~¥9:77:7:57;7;76;6346- |
  | --- AND catapult       |
  | *catapult*             |
  | ***catapult*           |
  | ****** catapult*       |

  @boolean_operators
  Scenario Outline: boolean operators in bad positions in the query are ignored and if the title isn't a valid article title then you don't get the option to create a new page
    When I search for <query>
    Then there is no warning
    And Catapult is in the first search result
    And there is no link to create a new page from the search result
  Examples:
  |         query          |
  | :~!$$=!~\!{<} catapult |
  | catapult -_~^_~^_^^    |
  | catapult \|\|          |
  | catapult ~~~~~~        |
  | catapult \|\|---       |
  | \|\| catapult          |

  @bad_syntax
  Scenario: searching for NOT something will not crash (technically it should bring up the most linked document, but this isn't worth checking)
    When I search for NOT catapult
    Then there is no warning
    And there is a search result
    And there is no link to create a new page from the search result

  Scenario Outline: searching for less than and greater than doesn't find tons and tons of tokens
    When I search for <query>
    Then there is no warning
    And there are no search results
    And there is no link to create a new page from the search result
  Examples:
    | query |
    | <}    |
    | <=}   |
    | >.    |
    | >=.   |
    | >     |
    | <     |
    | >>    |
    | <>    |

  @filters
  Scenario Outline: Empty filters work like terms but aren't in test data so aren't found
    When I search for <term>
    Then there is no warning
    And there are no search results
  Examples:
    |         term           |
    | intitle:"" catapult    |
    | incategory:"" catapult |
    | intitle:               |
    | intitle:""             |
    | incategory:            |
    | incategory:""          |
    | hastemplate:           |
    | hastemplate:""         |

  @wildcard
  Scenario Outline: Wildcards can't start a term but they aren't valid titles so you still don't get the link to create an article
    When I search for <wildcard>ickle
    Then there is no warning
    And there are no search results
    And there is a link to create a new page from the search result
  Examples:
    | wildcard |
    | *        |
    | ?        |

  Scenario Outline: Searching with a / doesn't cause a degraded search result
    When I search for main <term>
    Then Main Page is the first search result
  Examples:
    |      term      |
    | intitle:/page  |
    | Main/Page      |
