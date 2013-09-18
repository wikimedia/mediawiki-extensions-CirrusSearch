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
    | intitle:"" catapult                  | Catapult is                       | in              |        |
    | incategory:"" catapult               | Catapult is                       | in              |        |

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

  @setup_suggestions
  Scenario Outline: Suggestions
    When I search for <term>
    Then <suggestion> is suggested
  Examples:
    | term            | suggestion      |
    | popular culatur | popular culture |
    | noble prize     | nobel prize     |
    | nobel prize     | none            |

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
