Feature: Full text search
  Background:
    Given I am at a random page

  @setup_main
  Scenario Outline: Query string search
    When I search for <term>
    Then I am on a page titled Search results
    And <first_result> the first search <image?>result
    But Two Words is <two_words_is_in> the search results
  Examples:
    | term                                 | first_result                      | two_words_is_in | image? |
    | catapult                             | Catapult is in                    | in              |        |
    | pickles                              | Two Words is                      | in              |        |
    | catapul*                             | Catapult is in                    | in              |        |
    | rdir                                 | Two Words is                      | not in          |        |
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
    # You can't search for text inside a <video> or <audio> tag
    | "JavaScript disabled"                | none is                           | not in          |        |
    # You can't search for text inside the table of contants
    | "3.1 Conquest of Persian empire"     | none is                           | not in          |        |
    # You can't search for the [edit] tokens that users can click to edit sections
    | "Succession of Umar edit"            | none is                           | not in          |        |
    | intitle:"" catapult                  | none is                           | not in          |        |
    | incategory:"" catapult               | none is                           | not in          |        |

  @setup_main
  Scenario Outline: Searching for empty-string like values
    When I search for <term>
    Then I am on a page titled <title>
    And there are no search results
    And there are no errors reported
  Examples:
    | term             | title          |
    | the empty string | Search         |
    | ♙                | Search results |
    | intitle:         | Search results |
    | intitle:""       | Search results |
    | incategory:      | Search results |
    | incategory:""    | Search results |

  @Setup_suggestions
  Scenario: Common phrases spelled incorrectly get suggestions
    When I search for popular cultur
    Then popular *culture* is suggested

  @Setup_suggestions
  Scenario: Uncommon phrases spelled incorrectly get suggestions even if they contain words that are spelled correctly on their own
    When I search for noble prize
    Then *nobel* prize is suggested

  @Setup_suggestions
  Scenario: Uncommon phrases spelled correctly don't get suggestsions even if one of the words is very uncommon
    When I search for nobel prize
    Then there is no suggestion

  @setup_weight
  Scenario: Page weight include redirects
    When I search for TestWeight
    Then TestWeight Larger is the first search result
    And TestWeight Smaller is the second search result

  @setup_main
  Scenario: Pages can be found by their headings
    When I search for incategory:HeadingsTest "I am a heading"
    Then HasHeadings is the first search result

  @setup_headings
  Scenario: Ignored headings aren't searched so text with the same word is wins
    When I search for incategory:HeadingsTest References
    Then HasReferencesInText is the first search result

  @setup_main
  Scenario: Searching for a quoted category that doesn't exist finds nothing even though there is a category that matches one of the words
    When I search for incategory:"Dontfindme Weaponry"
    Then there are no search results

  @setup_main
  Scenario: Searching for a single word category doesn't find a two word category that contains that word
    When I search for incategory:ASpace
    Then there are no search results

  @setup_main
  Scenario: Searching for multiword category finds it
    When I search for incategory:"CategoryWith ASpace"
    Then IHaveATwoWordCategory is the first search result

  @setup_more_like_this
  Scenario: Searching for morelike:<page that doesn't exist> returns no results
    When I search for morelike:IDontExist
    Then there are no search results

  @setup_more_like_this
  Scenario: Searching for morelike:<page> returns pages that are 'like' that page
    When I search for morelike:More Like Me 1
    Then More Like Me is in the first search result
    But More Like Me 1 is not in the search results

  @setup_javascript_injection
  Scenario: Searching for a page with javascript doesn't execute it (in this case, removing the page title)
    When I search for Javascript findme
    Then the title still exists

 @setup_main
  Scenario: Searching for a page using its title and another word not in the page's text doesn't find the page
    When I search for DontExistWord Two Words
    Then there are no search results

  @setup_main
  Scenario: Searching for a page using its title and another word in the page's text does find it
    When I search for catapult Two Words
    Then Two Words is the first search result

  @setup_main
  Scenario: Searching for <text>~ activates fuzzy search
    When I search for ffnonesensewor~
    Then Two Words is the first search result

  @setup_main
  Scenario: Searching for <text>~<text> treats the tilde like a space (finding a result if the term is correct)
    When I search for ffnonesenseword~pickles
    Then Two Words is the first search result

  @setup_main
  Scenario: Searching for <text>~<text> treats the tilde like a space (not finding any results if a fuzzy search was needed)
    When I search for ffnonesensewor~pickles
    Then there are no search results

  @setup_main
  Scenario Outline: Searching for <text>~<number between 0 and 1> activates fuzzy search
    When I search for ffnonesensewor~<number>
    Then Two Words is the first search result
  Examples:
    | number |
    | .8     |
    | 0.8    |
    | 1      |

  @setup_main
  Scenario: Searching for <text>~0 activates fuzzy search but with 0 fuzziness (finding a result if the term is corret)
    When I search for ffnonesenseword~0
    Then Two Words is the first search result

  @setup_main
  Scenario: Searching for <text>~0 activates fuzzy search but with 0 fuzziness (finding nothing if fuzzy search is required)
    When I search for ffnonesensewor~0
    Then there are no search results

  @setup_main
  Scenario Outline: Searching for "<word> <word>"~<number> activates a proximity search
    When I search for "ffnonesenseword anotherword"~<proximity>
    Then <result> is the first search result
  Examples:
    | proximity | result    |
    | 0         | none      |
    | 1         | none      |
    | 2         | Two Words |
    | 3         | Two Words |
    | 77        | Two Words |

  @setup_main
  Scenario: Searching for "<word> <word>"~<not a numer> treats the ~ as a space
    When I search for "ffnonesenseword catapult"~anotherword
    Then Two Words is the first search result

  @setup_phrase_rescore
  Scenario: Searching for an unquoted phrase finds the phrase first
    When I search for Rescore Test
    Then Rescore Test Words is the first search result

  @setup_phrase_rescore
  Scenario: Searching for an a quoted phrase finds higher scored matches before the whole query interpreted as a phrase
    When I search for Rescore "Test Words"
    Then Test Words Rescore Rescore is the first search result

  # Note that other tests will catch this situation as well but this test should be pretty specific
  @setup_phrase_rescore
  Scenario: Searching for an unquoted phrase still prioritizes titles over text
    When I search for Rescore Test TextContent
    Then Rescore Test TextContent is the first search result
