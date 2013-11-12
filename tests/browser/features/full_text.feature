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
    | intitle:catapult                     | Catapult is in                    | not in          |        |
    | intitle:catapult amazing             | Amazing Catapult is               | not in          |        |
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
    | File:greyed                          | File:Savepage-greyed.png is       | not in          | image  |
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
    | term                    | title          |
    | the empty string        | Search         |
    | ♙                       | Search results |
    | intitle:                | Search results |
    | intitle:""              | Search results |
    | incategory:             | Search results |
    | incategory:""           | Search results |
    | %{exact: }              | Search results |
    | %{exact:      }         | Search results |
    | %{exact:              } | Search results |

  @setup_suggestions
  Scenario: Common phrases spelled incorrectly get suggestions
    When I search for popular cultur
    Then popular *culture* is suggested

  @setup_suggestions
  Scenario: Uncommon phrases spelled incorrectly get suggestions even if they contain words that are spelled correctly on their own
    When I search for noble prize
    Then *nobel* prize is suggested

  @setup_suggestions
  Scenario: Uncommon phrases spelled correctly don't get suggestions even if one of the words is very uncommon
    When I search for nobel prize
    Then there is no suggestion

  @setup_suggestions
  Scenario: Suggetions can come from redirect titles when redirects are included in search
    When I search for Rrr Ward
    Then rrr *word* is suggested

  @setup_suggestions
  Scenario: Suggetions don't come from redirect titles when redirects are not included in search
    Given I am at the search results page
    And I click the Advanced link
    And I click the List redirects label
    When I search for Rrr Ward
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

  @setup_main @balance_quotes
  Scenario Outline: Searching for for a phrase with a hanging quote adds the quote automatically
    When I search for <term>
    Then Two Words is the first search result
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
    Then <title> is the first search result
  Examples:
    |                        term                       |                   title                   |
    | "10.1093/acprof:oso/9780195314250.003.0001"       | 10.1093/acprof:oso/9780195314250.003.0001 |
    | "10.5194/os-8-1071-2012"                          | 10.5194/os-8-1071-2012                    |
    | "10.7227/rie.86.2"                                | 10.7227/rie.86.2                          |
    | "10.7227\"yay"                                    | 10.7227"yay                               |
    | intitle:"1911 Encyclopædia Britannica/Dionysius"' | 1911 Encyclopædia Britannica/Dionysius    |

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

  @setup_main @exact_quotes
  Scenario: Searching for a word in quotes disbles stemming (can't find plural with singular)
    When I search for "pickle"
    Then there are no search results

  @setup_main @exact_quotes
  Scenario: Searching for a word in quotes disbles stemming (can still find plural with exact match)
    When I search for "pickles"
    Then Two Words is the first search result

  @setup_main @exact_quotes
  Scenario: Searching for a phrase in quotes disbles stemming (can't find plural with singular)
    When I search for "catapult pickle"
    Then there are no search results

  @setup_main @exact_quotes
  Scenario: Searching for a phrase in quotes disbles stemming (can still find plural with exact match)
    When I search for "catapult pickles"
    Then Two Words is the first search result

  @exact_quotes
  Scenario: Quoted phrases match stop words
    When I search for "Contains A Stop Word"
    Then Contains A Stop Word is the first search result

  @setup_main @exact_quotes
  Scenario: Adding a ~ to a phrase keeps stemming enabled
    When I search for "catapult pickle"~
    Then Two Words is the first search result

  @exact_quotes
  Scenario: Adding a ~ to a phrase stops it from matching stop words
    When I search for "doesn't actually Contain A Stop Words"~
    Then Doesn't Actually Contain Stop Words is the first search result

  @setup_main @exact_quotes
  Scenario: Adding a ~<a number>~ to a phrase keeps stemming enabled
    When I search for "catapult pickle"~0~
    Then Two Words is the first search result

  @setup_main @exact_quotes
  Scenario: Adding a ~<a number> to a phrase turns off because it is a proximity search
    When I search for "catapult pickle"~0
    Then there are no search results

  @exact_quotes
  Scenario: Searching for a quoted * actually searches for a *
    When I search for "pick*"
    Then Pick* is the first search result

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
    Then <result> is the first search result
  Examples:
    |   term   |        result        |
    | aliases  | StemmerTest Aliases  |
    | alias    | StemmerTest Aliases  |
    | used     | StemmerTest Used     |
    | uses     | StemmerTest Used     |
    | use      | StemmerTest Used     |
    | us       | none                 |

  @prefix_filter
  Scenario: The prefix: filter filters results to those with titles prefixed by value
    When I search for prefix prefix:prefix
    Then Prefix Test is the first search result
    But Foo Prefix Test is not in the search results

  @prefix_filter
  Scenario: The prefix: filter interprets spaces literally
    When I search for prefix prefix:prefix tes
    Then Prefix Test is the first search result

  @prefix_filter
  Scenario: It is ok to start the query with the prefix filter
    When I search for prefix:prefix tes
    Then Prefix Test is the first search result

  @prefix_filter
  Scenario: It is ok to specify an empty prefix filter
    When I search for prefix test prefix:
    Then Prefix Test is the first search result

  @prefix_filter
  Scenario: The prefix: filter can be used to apply a namespace and a title prefix
    When I search for prefix:talk:prefix tes
    Then Talk:Prefix Test is the first search result
    But Prefix Test is not in the search results

  @prefix_filter
  Scenario: The prefix: filter can be used to apply a namespace without a title prefix
    When I search for prefix test prefix:talk:
    Then Talk:Prefix Test is the first search result
    But Prefix Test is not in the search results

  @prefix_filter
  Scenario: The prefix: filter can be used to filter to subpages
    When I search for prefix test aaaa prefix:Prefix Test/
    Then Prefix Test/AAAA is the first search result
    But Prefix Test AAAA is not in the search results

  @prefix_filter
  Scenario: The prefix: filter can be used to filter to subpages starting with some title
    When I search for prefix test aaaa prefix:Prefix Test/aa
    Then Prefix Test/AAAA is the first search result
    But Prefix Test AAAA is not in the search results

  @boolean_operators @setup_main
  Scenario Outline: -, !, and NOT prohibit words in search results
    When I search for <query>
    Then Catapult is the first search result
    But Amazing Catapult is not in the search results
  Examples:
  |        query         |
  | catapult -amazing    |
  | -amazing catapult    |
  | catapult !amazing    |
  | !amazing catapult    |
  | catapult NOT amazing |
  | NOT amazing catapult |

  @boolean_operators @setup_main
  Scenario Outline: +, &&, and AND require matches but since that is the default they don't look like they do anything
    When I search for <query>
    Then Amazing Catapult is the first search result
    But Catapult is not in the search results
  Examples:
  |         query         |
  | +catapult amazing     |
  | amazing +catapult     |
  | +amazing +catapult    |
  | catapult AND amazing  |

  @boolean_operators @setup_main
  Scenario Outline: OR and || matches docs with either set
    When I search for <query>
    Then Catapult is in the search results
    And Two Words is in the search results
  Examples:
  |          query          |
  | catapult OR África      |
  | África \|\| catapult    |
  | catapult OR "África"   |
  | catapult \|\| "África" |
  | "África" OR catapult   |
  | "África" \|\| catapult |

  @boolean_operators @setup_main
  Scenario Outline: boolean operators in bad positions in the query are ignored
    When I search for <query>
    Then Catapult is in the first search result
  Examples:
  |         query          |
  | catapult +             |
  | catapult -             |
  | catapult !             |
  | catapult AND           |
  | catapult OR            |
  | catapult NOT           |
  | + catapult             |
  | - catapult             |
  | ! catapult             |
  | AND catapult           |
  | OR catapult            |
  | catapult + amazing     |
  | catapult - amazing     |
  | catapult ! amazing     |
  | catapult AND + amazing |
  | catapult AND - amazing |
  | catapult AND ! amazing |

  @boolean_operators @setup_main
  Scenario: searching for NOT something will not crash (technically it should bring up the most linked document, but this isn't worth checking)
    When I search for NOT catapult
    Then there is a search result

  @wildcards @setup_main
  Scenario: searching with a single wildcard finds expected results
    When I search for catapul*
    Then Catapult is the first search result

  @wildcards @setup_main
  Scenario: searching for intitle: with a wildcard find expected results
    When I search for intitle:catapul*
    Then Catapult is the first search result

  @wildcards @setup_main
  Scenario: searching for intitle: with a wildcard and a regular wildcard find expected results
    When I search for intitle:catapul* amaz*
    Then Amazing Catapult is the first search result

  @wildcards @setup_main
  Scenario: wildcards match plain matches
    When I search for pi*les
    Then Two Words is the first search result

  @wildcards @setup_main
  Scenario: wildcards don't match stemmed matches
    When I search for pi*le
    Then there are no search results

  @prefer_recent
  Scenario Outline: Recently updated articles are prefered if prefer-recent: is specified
    When I search for PreferRecent First OR Second OR Third
    Then PreferRecent Second Second is the first search result
    When I search for prefer-recent:<options> PreferRecent First OR Second OR Third
    Then PreferRecent Third is the first search result
  Examples:
    |   options   |
    | 1,.001      |
    | 1,0.001     |
    | 1,.0001     |
    | .99,.0001   |
    | .99,.001    |
    | .8,.0001    |
    | .7,.0001    |

  @prefer_recent
  Scenario Outline: You can specify prefer-recent: in such a way that being super recent isn't enough
    When I search for prefer-recent:<options> PreferRecent First OR Second OR Third
    Then PreferRecent Second Second is the first search result
  Examples:
    |  options  |
    |           |
    | 1         |
    | 1,1       |
    | 1,.1      |
    | .4,.0001  |
