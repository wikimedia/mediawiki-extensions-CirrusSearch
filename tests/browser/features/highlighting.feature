@clean @phantomjs @highlighting
Feature: Highlighting
  Background:
    Given I am at a random page

  @setup_main
  Scenario Outline: Found words are highlighted
    When I search for <term>
    Then I am on a page titled Search results
    And <highlighted_title> is the highlighted title of the first search result
    And <highlighted_text> is the highlighted text of the first search result
  Examples:
    | term                       | highlighted_title        | highlighted_text                                 |
    | two words                  | *Two* *Words*            | ffnonesenseword catapult pickles anotherword     |
    | pickles                    | Two Words                | ffnonesenseword catapult *pickles* anotherword   |
    | ffnonesenseword pickles    | Two Words                | *ffnonesenseword* catapult *pickles* anotherword |
    | two words catapult pickles | *Two* *Words*            | ffnonesenseword *catapult* *pickles* anotherword |
    | template:test pickle       | Template:Template *Test* | *pickles*                                        |
    # Verify highlighting the presence of accent squashing
    | Africa test                | *África*                 | for *testing*                                    |
    # Verify highlighting on large pages.
    | "discuss problems of social and cultural importance" | Rashidun Caliphate | gathered to *discuss* *problems* *of* *social* *and* *cultural* *importance*. During the caliphate *of* Umar as many |
    | "discuss problems of social and cultural importance"~ | Rashidun Caliphate | gathered to *discuss* *problems* *of* *social* *and* *cultural* *importance*. During the caliphate *of* Umar as many |
    # Auxiliary text
    | tallest alborz             | Rashidun Caliphate       | Mount Damavand, Iran's *tallest* mountain is located in *Alborz* mountain range. |

  Scenario: Even stopwords are highlighted
    When I search for the once and future king
    Then *The* *Once* *and* *Future* *King* is the highlighted title of the first search result

  Scenario: Found words are highlighted even if found by different analyzers
    When I search for "threatening the unity" community
    Then *threatening* *the* *unity* and stability of *the* new *community* is in the highlighted text of the first search result

  @headings
  Scenario: Found words are highlighted in headings
    When I search for "i am a heading"
    Then *I* *am* *a* *heading* is the highlighted alttitle of the first search result

  Scenario: Found words are highlighted in headings even in large documents
    When I search for "Succession of Umar"
    Then *Succession* *of* *Umar* is the highlighted alttitle of the first search result

  Scenario: Found words are highlighted in text even in large documents
    When I search for Allowance to non-Muslims
    Then *Allowance* *to* *non*-*Muslims* is in the highlighted text of the first search result

  Scenario: Found words are highlighted in text even in large documents
    When I search for "Allowance to non-Muslims"
    Then *Allowance* *to* *non*-*Muslims* is in the highlighted text of the first search result

  Scenario: Words are not found in image captions
    When I search for The Rose Trellis Egg
    Then *The* *Rose* *Trellis* Faberge *Egg* is a jewelled enameled imperial Easter *egg* made in St. Petersburg is the highlighted text of the first search result

  @headings
  Scenario: Found words are highlighted in headings even if they contain both a phrase and a non-phrase
    When I search for "i am a" heading
    Then *I* *am* *a* *heading* is the highlighted alttitle of the first search result

  @headings
  Scenario: Found words are highlighted in headings when searching for a non-strict phrase
    When I search for "i am a heading"~
    Then *I* *am* *a* *heading* is the highlighted alttitle of the first search result

  @headings
  Scenario: Found words are highlighted in headings even in large documents when searching in a non-strict phrase
    When I search for "Succession of Umar"~
    Then *Succession* *of* *Umar* is the highlighted alttitle of the first search result

  Scenario: Found words are highlighted in headings even in large documents when searching in a non-strict phrase
    When I search for "Allowance to non-Muslims"~
    Then *Allowance* *to* *non*-*Muslims* is in the highlighted text of the first search result

  @headings
  Scenario: The highest scoring heading is highlighted AND it doesn't contain html even if the heading on the page does
    When I search for bold heading
    Then I am a *bold* *heading* is the highlighted alttitle of the first search result

  @headings
  Scenario: HTML comments in headings are not highlighted
    When I search for Heading with html comment
    And *Heading* *with* *html* *comment* is the highlighted alttitle of the first search result

  Scenario: Redirects are highlighted
    When I search for rdir
    And *Rdir* is the highlighted alttitle of the first search result

  Scenario: The highest scoring redirect is highlighted
    When I search for crazy rdir
    Then *Crazy* *Rdir* is the highlighted alttitle of the first search result

  Scenario: Highlighted titles don't contain underscores in the namespace
    When I search for user_talk:test
    Then User talk:*Test* is the highlighted title of the first search result

  Scenario: Highlighted text prefers the beginning of the article
    When I search for Rashidun Caliphate
    Then Template:History of the Arab States The *Rashidun* *Caliphate* (Template:lang-ar al-khilafat ar-Rāshidīyah) is the highlighted text of the first search result
    When I search for caliphs
    Then The first four *caliphs* are called the Rashidun, meaning the Rightly Guided *Caliphs*, because they are is the highlighted text of the first search result

  @references
  Scenario: References don't appear in highlighted section titles
    When I search for Reference Section Highlight Test
    And *Reference* *Section* is the highlighted alttitle of the first search result

  @references
  Scenario: References ([1]) don't appear in highlighted text
    When I search for Reference Text Highlight Test
    And *Reference* *Text*   foo   baz   bar is the highlighted text of the first search result

  @references
  Scenario: References are highlighted if you search for them
    When I search for Reference foo bar baz Highlight Test
    And *Reference* Text   *foo*   *baz*   *bar* is the highlighted text of the first search result

  @programmer_friendly
  Scenario: camelCase is highlighted correctly
    When I search for namespace aliases
    Then $wg*Namespace**Aliases* is the highlighted title of the first search result

  @file_text
  Scenario: When you search for text that is in a file if there are no matches on the page you get the highlighted text from the file
    When I search for File:debian rhino
    Then File:Linux Distribution Timeline text version.pdf is the first search imageresult
    And *Debian* is in the highlighted text of the first search result
    And Arco-*Debian* is in the highlighted text of the first search result
    And Black*Rhino* is in the highlighted text of the first search result
    And (matches file content) is the highlighted alttitle of the first search result

  @file_text
  Scenario: When you search for text that is in a file if there are matches on the page you get those
    When I search for File:debian rhino linux
    Then File:Linux Distribution Timeline text version.pdf is the first search imageresult
    And *Linux* distribution timeline. is the highlighted text of the first search result

  @redirect
  Scenario: Redirects containing &s are highlighted
    Given a page named Highlight & Ampersand exists with contents #REDIRECT [[Main Page]]
    When I search for Highlight Ampersand
    Then *Highlight* &amp; *Ampersand* is the highlighted alttitle of the first search result

  @redirect
  Scenario: The best matched redirect is highlighted
    Given a page named Rrrrtest Foorr exists with contents #REDIRECT [[Main Page]]
    And a page named Rrrrtest Foorr Barr exists with contents #REDIRECT [[Main Page]]
    And a page named Rrrrtest exists with contents #REDIRECT [[Main Page]]
    When I search for Rrrrtest Foorr Barr
    Then *Rrrrtest* *Foorr* *Barr* is the highlighted alttitle of the first search result

  @redirect
  Scenario: Long redirects are highlighted
    Given a page named Joint Declaration of the Government of the United Kingdom of Great Britain and Northern Ireland and the Government of the People's Republic of China on the Question of Hong Kong exists with contents #REDIRECT [[Main Page]]
    When I search for Joint Declaration of the Government of the United Kingdom of Great Britain and Northern Ireland and the Government of the People's Republic of China on the Question of Hong Kong
    Then *Joint* *Declaration* *of* *the* *Government* *of* *the* *United* *Kingdom* *of* *Great* *Britain* *and* *Northern* *Ireland* *and* *the* *Government* *of* *the* *People's* *Republic* *of* *China* *on* *the* *Question* *of* *Hong* *Kong* is the highlighted alttitle of the first search result
