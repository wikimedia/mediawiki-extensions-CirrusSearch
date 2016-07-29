@clean @phantomjs @bad_syntax
Feature: Searches that prompt, or not, for new page creation
  Background:
    Given I am at a random page

  @incategory @wildcard
  Scenario Outline: Something something
    When I search for <query>
    Then there is <condition> to create a new page from the search result
  Examples:
  |               query                                       | condition |
  | "ffnonsesnseword catapult"~anotherword                    |  no link  |
  | catapult~~~~....[[\|\|.\|\|\|\|\|\|+\|+\|=\\\\=\\*.$.$.$. |  no link  |
  | \|\| catapult                                             |  no link  |
  | *ickle                                                    |   a link  |
  | incategory:weaponry                                       |  no link  |
  | catapu\\?t                                                |  no link  |
  | catapul\\?                                                |  no link  |
  | morelike:ThisPageDoesNotExist                             |  no link  |
  | morelike:ChangeMe                                         |  no link  |

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
  | ++amazing++++catapult  |
  | catapult ~/            |
  | catapult ~/            |
  | amazing~◆~catapult     |
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

