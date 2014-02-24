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
    | rdir                                 | Two Words is                      | not in          |        |
    | talk:catapult                        | Talk:Two Words is                 | not in          |        |
    | talk:intitle:words                   | Talk:Two Words is                 | not in          |        |
    | template:pickles                     | Template:Template Test is         | not in          |        |
    | pickles/                             | Two Words is                      | in              |        |
    | catapult/pickles                     | Two Words is                      | in              |        |
    # Make sure various ways of searching for a file name work
    | File:Savepage-greyed.png             | File:Savepage-greyed.png is       | not in          | image  |
    | File:Savepage                        | File:Savepage-greyed.png is       | not in          | image  |
    | File:greyed.png                      | File:Savepage-greyed.png is       | not in          | image  |
    | File:greyed                          | File:Savepage-greyed.png is       | not in          | image  |
    | File:"Screenshot, for test purposes" | File:Savepage-greyed.png is       | not in          | image  |
    # You can't search for text inside a <video> or <audio> tag
    | "JavaScript disabled"                | none is                           | not in          |        |
    # You can't search for text inside the table of contants
    | "3.1 Conquest of Persian empire"     | none is                           | not in          |        |
    # You can't search for the [edit] tokens that users can click to edit sections
    | "Succession of Umar edit"            | none is                           | not in          |        |

  @setup_main
  Scenario Outline: Searching for empty-string like values
    When I search for <term>
    Then I am on a page titled <title>
    And there are no search results
    And there are no errors reported
  Examples:
    | term                    | title          |
    | the empty string        | Search         |
    | ♙                       | Search results |
    | %{exact: }              | Search results |
    | %{exact:      }         | Search results |
    | %{exact:              } | Search results |

  @setup_weight
  Scenario: Page weight include redirects
    When I search for TestWeight
    Then TestWeight Larger is the first search result
    And TestWeight Smaller is the second search result

  @setup_main
  Scenario: Pages can be found by their headings
    When I search for incategory:HeadingsTest "I am a heading"
    Then HasHeadings is the first search result

  @headings
  Scenario: Ignored headings aren't searched so text with the same word is wins
    When I search for incategory:HeadingsTest References
    Then HasReferencesInText is the first search result

  @setup_more_like_this
  Scenario: Searching for morelike:<page that doesn't exist> returns no results
    When I search for morelike:IDontExist
    Then there are no search results

  @setup_more_like_this
  Scenario: Searching for morelike:<page> returns pages that are "like" that page
    When I search for morelike:More Like Me 1
    Then More Like Me is in the first search result
    But More Like Me 1 is not in the search results

  @javascript_injection
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

  @setup_phrase_rescore
  Scenario: Searching for an unquoted phrase finds the phrase first
    When I search for Rescore Test Words
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

  @setup_phrase_rescore
  Scenario: Searching with a quoted word just treats the word as though it didn't have quotes
    When I search for "Rescore" Words Test
    Then Test Words Rescore Rescore is the first search result

  @programmer_friendly
  Scenario Outline: Programmer friendly searches
    When I search for <term>
    Then <page> is the first search result
  Examples:
    | term                | page                |
    | namespace aliases   | $wgNamespaceAliases |
    | namespaceAliases    | $wgNamespaceAliases |
    | $wgNamespaceAliases | $wgNamespaceAliases |
    | namespace_aliases   | $wgNamespaceAliases |
    | NamespaceAliases    | $wgNamespaceAliases |
    | snake case          | PFSC                |
    | snakeCase           | PFSC                |
    | snake_case          | PFSC                |
    | SnakeCase           | PFSC                |
    | Pascal Case         | PascalCase          |
    | pascalCase          | PascalCase          |
    | pascal_case         | PascalCase          |
    | PascalCase          | PascalCase          |

  @stemmer
  Scenario Outline: Stemming works as expected
    When I search for StemmerTest <term>
    Then <first_result> is the first search result
    Then <second_result> is the second search result
  Examples:
    |   term   |     first_result     |    second_result    |
    | aliases  | StemmerTest Aliases  | StemmerTest Alias   |
    | alias    | StemmerTest Alias    | StemmerTest Aliases |
    | used     | StemmerTest Used     | none                |
    | uses     | StemmerTest Used     | none                |
    | use      | StemmerTest Used     | none                |
    | us       | none                 | none                |

  @file_text
  Scenario: When you search for text that is in a file, you can find it!
    When I search for File:debian rhino
    Then File:Linux Distribution Timeline text version.pdf is the first search imageresult

  @match_stopwords
  Scenario: When you search for a stopword you find pages with that stopword
    When I search for to -intitle:Manyredirectstarget
    Then To is the first search result

  @many_redirects
  Scenario: When you search for a page by redirects having more unrelated redirects doesn't penalize the score
    When I search for incategory:ManyRedirectsTest Many Redirects Test
    Then Manyredirectstarget is the first search result

  @relevancy
  Scenario: Results are sorted in the order we expect
    When I search for Relevancytest
    Then Relevancytest is the first search result
    And Relevancytestviaredirect is the second search result
    And Relevancytestviaheading is the third search result
    And Relevancytestviatext is the fourth search result

  @relevancy
  Scenario: Two word searches are sorted in the order we expect
    When I search for Relevancytwo Wordtest
    Then Relevancytwo Wordtest is the first search result
    And Wordtest Relevancytwo is the second search result

  @relevancy
  Scenario: Results are effected by the namespace boost
    When I search for all:Relevancynamespacetest
    Then Relevancynamespacetest is the first search result
    And Talk:Relevancynamespacetest is the second search result
    And File:Relevancynamespacetest is the third search result
    And Help:Relevancynamespacetest is the fourth search result
    And File talk:Relevancynamespacetest is the fifth search result
    And User talk:Relevancynamespacetest is the sixth search result
    And Template:Relevancynamespacetest is the seventh search result

  @fallback_finder
  Scenario: I can find things that Elasticsearch typically thinks of as word breaks in the title
    When I search for $US
    Then $US is the first search result

  @fallback_finder
  Scenario: I can find things that Elaticsearch typically thinks of as word breaks in redirect title
    When I search for ¢
    Then Cent (currency) is the first search result

  @js_and_css
  Scenario: JS pages don't corrupt the output
    When I search for User:Tools/some.js jQuery
    Then there is not alttitle on the first search result

  @js_and_css
  Scenario: CSS pages don't corrupt the output
    When I search for User:Tools/some.css jQuery
    Then there is not alttitle on the first search result
