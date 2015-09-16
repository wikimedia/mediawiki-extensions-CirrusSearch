@clean @api @relevancy
Feature: Common Terms Query
  Scenario: The default query string builder is strict
    When I api search for this is not a relevant Relevancytest
    Then there are no api search results

  Scenario: Commons term query allows to find results when stopwords are missing
    When I activate common terms query with the default profile
      And I api search for this is not a relevant Relevancytest
    Then Relevancytest is in the api search results
      And Relevancytestviaredirect is in the api search results

  Scenario Outline: Commons term query is disabled when there is special syntax
    When I activate common terms query with the default profile
      And I api search for <query> this is +not a relevant Relevancytest
    Then there are no api search results
  Examples:
    |                query                 |
    |this is +not a relevant Relevancytest |
    |this intitle:Relevancytest            |
    |this is not a "relevant Relevancytest"|
    |this is not a releva* Relevancytest   |

  Scenario: The aggressive recall profile will display results even if some words are missing
    When I activate common terms query with the aggressive_recall profile
      And I api search for a cool wordtest with Relevancytwo is bliss
    Then Relevancytwo Wordtest is the first api search result

  Scenario: With common terms query stop words are used to boost relevancy
    When I activate common terms query with the default profile
      And I api search for Shakespeare to be or not to be
    Then William Shakespeare Works is the first api search result
      And William Shakespeare is the second api search result
