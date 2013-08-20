Feature: Full text search
  @setup_main
  Scenario Outline: Query string search
    Given I am at a random page
    When I search for <term>
    Then I am on a page titled Search results
    And <first_result> the first search <image?>result
    But Two Words is <two_words_is_in> the search results
  Examples:
    | term                                 | first_result                      | two_words_is_in | image? |
    | catapult                             | Catapult is in                    | in              |        |
    | pickles                              | Two Words is                      | in              |        |
    | catapul*                             | Catapult is in                    | in              |        |
    | rdir                                 | Two Words (redirect is in         | not in          |        |
    | intitle:catapult                     | Catapult is in                    | not in          |        |
    | intitle:catapul*                     | Catapult is in                    | not in          |        |
    | intitle:catapult amazing             | Amazing Catapult is               | not in          |        |
    | intitle:catapul* amaz*               | Amazing Catapult is               | not in          |        |
    | incategory:weaponry                  | Catapult is in                    | not in          |        |
    | incategory:weaponry amazing          | Amazing Catapult is               | not in          |        |
    | incategory:weaponry intitle:catapult | Catapult is in                    | not in          |        |
    | incategory:alpha incategory:beta     | AlphaBeta is                      | not in          |        |
    | incategory:twowords catapult         | Two Words is                      | in              |        |
    | incategory:twowords intitle:catapult | none is                           | not in          |        |
    | incategory:templatetagged two words  | Two Words is                      | in              |        |
    | talk:catapult                        | Talk:Two Words is                 | not in          |        |
    | talk:intitle:words                   | Talk:Two Words is                 | not in          |        |
    | template:pickles                     | Template:Template Test is         | not in          |        |
    | pickles/                             | Two Words is                      | in              |        |
    | catapult/pickles                     | Two Words is                      | in              |        |
    # Make sure various ways of searching for a file name work
    | File:Savepage-greyed.png             | File:Savepage-greyed.png is       | not in          | image  |
    | File:Savepage                        | File:Savepage-greyed.png is       | not in          | image  |
    | File:greyed.png                      | File:Savepage-greyed.png is       | not in          | image  |
    # Bug 52948
    #| File:greyed                          | File:Savepage-greyed.png is       | not in          | image  |
    | File:"Screenshot, for test purposes" | File:Savepage-greyed.png is       | not in          | image  |

  @setup_main
  Scenario Outline: Searching for empty-string like values
    Given I am at a random page
    When I search for <term>
    Then I am on a page titled <title>
    And there are no search results
  Examples:
    | term             | title          |
    | the empty string | Search         |
    | ♙                | Search results |

  @setup_main
  @setup_namespaces
  Scenario Outline: Main search with non-advanced clicky features
    Given I am at the search results page
    When I click the <filter> link
    And I search for <term>
    Then I am on a page titled Search results
    And <first_result> is the first search result
  Examples:
    | filter                 | term         | first_result     |
    | Content pages          | catapult     | Catapult         |
    | Content pages          | smoosh       | none             |
    | Content pages          | nothingasdf  | none             |
    | Help and Project pages | catapult     | none             |
    | Help and Project pages | smoosh       | Help:Smoosh      |
    | Help and Project pages | nothingasdf  | none             |
    | Multimedia             | catapult     | none             |
    | Multimedia             | smoosh       | none             |
    | Multimedia             | nothingasdf  | File:Nothingasdf |
    | Everything             | catapult     | Catapult         |
    | Everything             | smoosh       | Help:Smoosh      |
    | Everything             | nothingasdf  | File:Nothingasdf |

  @setup_main
  @setup_namespaces
  Scenario Outline: Main search with advanced clicky features
    Given I am at the search results page
    When I click the Advanced link
    And I click the (Main) or (Article) label
    And I click the <filters> labels
    And I search for <term>
    Then I am on a page titled Search results
    And <first_result> the first search result
  Examples:
    | filters             | term     | first_result      |
    | Talk, Help          | catapult | Talk:Two Words is |
    | Help, Help talk     | catapult | none is           |
    | (Main) or (Article) | catapult | Catapult is in    |
    | List redirects      | rdir     | none is           |

  @setup_suggestions
  Scenario Outline: Suggestions
    Given I am at a random page
    When I search for <term>
    Then <suggestion> is suggested
  Examples:
    | term            | suggestion      |
    | popular culatur | popular culture |
    | noble prize     | nobel prize     |
    | nobel prize     | none            |
