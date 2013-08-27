Feature: Full text search highlighting
  @setup_main @setup_highlighting
  Scenario Outline: Found words are highlighted
    Given I am at a random page
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