Feature: Full text search highlighting
  Background:
    Given I am at a random page

  @setup_main @setup_highlighting
  Scenario Outline: Found words are highlighted
    When I search for <term>
    Then I am on a page titled Search results
    And <highlighted_title> is the highlighted title of the first search result
    And <highlighted_text> is the highlighted text of the first search result
  Examples:
    | term                       | highlighted_title        | highlighted_text                     |
    | two words                  | *Two* *Words*            | ffnonesenseword catapult pickles     |
    | pickles                    | Two Words                | ffnonesenseword catapult *pickles*   |
    | ffnonesenseword pickles    | Two Words                | *ffnonesenseword* catapult *pickles* |
    | two words catapult pickles | *Two* *Words*            | ffnonesenseword *catapult* *pickles* |
    | template:test pickle       | Template:Template *Test* | *pickles*                            |
    # Verify highlighting the presence of accent squashing
    | Africa test                | *África*                 | for *testing*                        |
    # Verify highlighting on large pages (Bug 52680).  It is neat to see that the stopwords aren't highlighted.
    | "discuss problems of social and cultural importance" | Rashidun Caliphate | the faithful gathered to *discuss problems* of *social* and *cultural importance*. During the caliphate of |

  @setup_headings
  Scenario: Found words are highlighted in headings
    When I search for "i am a heading"
    Then *I* *am* a *heading* is the highlighted alttitle of the first search result

  @setup_highlighting
  Scenario: Found words are highlighted in headings and text even in large documents
    When I search for "Succession of Umar"
    Then *Succession* of *Umar* is the highlighted alttitle of the first search result
    And *Succession* of *Umar* is in the highlighted text of the first search result

  @setup_headings
  Scenario: The highest scoring heading is highlighted AND it doesn't contain html even if the heading on the page does
    When I search for bold heading
    Then I am a *bold* *heading* is the highlighted alttitle of the first search result

  @setup_highlighting
  Scenario: Redirects are highlighted
    When I search for rdir
    And *Rdir* is the highlighted alttitle of the first search result

  # Disabled until bug 54278 is fixed
  # @setup_highlighting
  # Scenario: The highest scoring redirect is highlighted
  #   When I search for crazy rdir
  #   Then *Crazy* *Rdir* is the highlighted alttitle of the first search result
