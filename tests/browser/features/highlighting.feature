@clean
Feature: Highlighting
  Background:
    Given I am at a random page

  @setup_main @highlighting
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
    # Verify highlighting on large pages (Bug 52680).  It is neat to see that the stopwords aren't highlighted.
    | "discuss problems of social and cultural importance" | Rashidun Caliphate | the faithful gathered to *discuss problems of social and cultural importance*. During the caliphate of |
    | "discuss problems of social and cultural importance"~ | Rashidun Caliphate | the faithful gathered to *discuss problems of social and cultural importance*. During the caliphate of |

  @highlighting
  Scenario: Even stopwords are highlighted
    When I search for the once and future king
    Then *The* *Once* *and* *Future* *King* is the highlighted title of the first search result

  @highlighting
  Scenario: Found words are highlighted even if found by different analyzers
    When I search for "threatening the unity" community
    Then *threatening the unity* and stability of the new *community* is in the highlighted text of the first search result

  @headings @highlighting
  Scenario: Found words are highlighted in headings
    When I search for "i am a heading"
    Then *I am a heading* is the highlighted alttitle of the first search result

  @highlighting
  Scenario: Found words are highlighted in headings and text even in large documents
    When I search for "Succession of Umar"
    Then *Succession of Umar* is the highlighted alttitle of the first search result
    And *Succession of Umar* is in the highlighted text of the first search result

  @highlighting
  Scenario: Words are not found in image captions
    When I search for The Rose Trellis Egg
    Then *The* *Rose* *Trellis* Faberge *Egg* is a jewelled enameled imperial Easter *egg* made in St. Petersburg, Russia is the highlighted text of the first search result

  @headings
  Scenario: Found words are highlighted in headings even if they contain both a phrase and a non-phrase
    When I search for "i am a" heading
    Then *I am a* *heading* is the highlighted alttitle of the first search result

  @headings @highlighting
  Scenario: Found words are highlighted in headings when searching for a non-strict phrase
    When I search for "i am a heading"~
    Then *I am a heading* is the highlighted alttitle of the first search result

  @headings @highlighting
  Scenario: Found words are highlighted in headings and text even in large documents when searching in a non-strict phrase
    When I search for "Succession of Umar"~
    Then *Succession of Umar* is the highlighted alttitle of the first search result
    And *Succession of Umar* is in the highlighted text of the first search result

  @headings @highlighting
  Scenario: The highest scoring heading is highlighted AND it doesn't contain html even if the heading on the page does
    When I search for bold heading
    Then I am a *bold* *heading* is the highlighted alttitle of the first search result

  @headings @highlighting
  Scenario: HTML comments in headings are not highlighted
    When I search for Heading with html comment
    And *Heading* *with* *html* *comment* is the highlighted alttitle of the first search result

  @highlighting
  Scenario: Redirects are highlighted
    When I search for rdir
    And *Rdir* is the highlighted alttitle of the first search result

  @highlighting
  Scenario: The highest scoring redirect is highlighted
    When I search for crazy rdir
    Then *Crazy* *Rdir* is the highlighted alttitle of the first search result

  @highlighting
  Scenario: Highlighted titles don't contain underscores in the namespace
    When I search for user_talk:test
    Then User talk:*Test* is the highlighted title of the first search result

  @programmer_friendly @highlighting
  Scenario: camelCase is highlighted correctly
    When I search for namespace aliases
    Then $wg*NamespaceAliases* is the highlighted title of the first search result

  @file_text @highlighting
  Scenario: When you search for text that is in a file if there are no matches on the page you get the highlighted text from the file
    When I search for File:debian rhino
    Then File:Linux Distribution Timeline text version.pdf is the first search imageresult
    And *Debian* is in the highlighted text of the first search result
    And Arco-*Debian* is in the highlighted text of the first search result
    And Black*Rhino* is in the highlighted text of the first search result
    And (matches file content) is the highlighted alttitle of the first search result

  @file_text @highlighting
  Scenario: When you search for text that is in a file if there are matches on the page you get those
    When I search for File:debian rhino linux
    Then File:Linux Distribution Timeline text version.pdf is the first search imageresult
    And *Linux* distribution timeline. is the highlighted text of the first search result

  @redirect @highlighting
  Scenario: Redirects containing &s are highlighted
    Given a page named Highlight & Ampersand exists with contents #REDIRECT [[Main Page]]
    When I search for Highlight Ampersand
    Then *Highlight* &amp; *Ampersand* is the highlighted alttitle of the first search result

  @redirect @highlighting
  Scenario: The best matched redirect is highlighted
    Given a page named Rrrrtest Foorr exists with contents #REDIRECT [[Main Page]]
    And a page named Rrrrtest Foorr Barr exists with contents #REDIRECT [[Main Page]]
    And a page named Rrrrtest exists with contents #REDIRECT [[Main Page]]
    When I search for Rrrrtest Foorr Barr
    Then *Rrrrtest* *Foorr* *Barr* is the highlighted alttitle of the first search result

  @redirect @highlighting
  Scenario: Long redirects are highlighted
    Given a page named Joint Declaration of the Government of the United Kingdom of Great Britain and Northern Ireland and the Government of the People's Republic of China on the Question of Hong Kong exists with contents #REDIRECT [[Main Page]]
    When I search for Joint Declaration of the Government of the United Kingdom of Great Britain and Northern Ireland and the Government of the People's Republic of China on the Question of Hong Kong
    Then *Joint* *Declaration* *of* *the* *Government* *of* *the* *United* *Kingdom* *of* *Great* *Britain* *and* *Northern* *Ireland* *and* *the* *Government* *of* *the* *People's* *Republic* *of* *China* *on* *the* *Question* *of* *Hong* *Kong* is the highlighted alttitle of the first search result
