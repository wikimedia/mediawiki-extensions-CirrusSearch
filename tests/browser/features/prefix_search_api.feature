@clean @api @prefix
Feature: Prefix search via api
  Scenario: Suggestions don't appear when you search for a string that is too long
    When I get api suggestions for 贵州省瞬时速度团头鲂身体c实施ysstsstsg说tyttxy以推销员会同香港推广系统在同他讨厌她团体淘汰>赛系统大选于它拥有一天天用于与体育学院国ttxzyttxtxytdttyyyztdsytstsstxtttd天天体育系统的摄像头听到他他偷笑>偷笑太阳团体杏眼桃腮他要tttxx y
    Then the api warns Prefix search request was longer than the maximum allowed length. (314 > 255)

  @redirect
  Scenario: Prefix search lists page name if both redirect and page name match
    When I get api suggestions for Redirecttest Y
    Then Redirecttest Yay is the first api suggestion
      And Redirecttest Yikes is not in the api suggestions

  @redirect
  Scenario: Prefix search ranks redirects under title matches
    When I get api suggestions for PrefixRedirectRanking
    Then PrefixRedirectRanking 1 is the first api suggestion
      And PrefixRedirectRanking 2 is the second api suggestion

  @accent_squashing
  Scenario Outline: Search suggestions with accents
    When I get api suggestions for <term>
    Then <first_suggestion> is the first api suggestion
      And <second_suggestion> is the second api suggestion
  Examples:
    |      term      | first_suggestion | second_suggestion |
    | Áccent Sorting | Áccent Sorting   | Accent Sorting    |
    | áccent Sorting | Áccent Sorting   | Accent Sorting    |
    | Accent Sorting | Accent Sorting   | Áccent Sorting    |
    | accent Sorting | Accent Sorting   | Áccent Sorting    |

  Scenario: Searching for a bare namespace finds everything in the namespace
    Given a page named Template talk:Foo exists
      And within 20 seconds api searching for Template talk:Foo yields Template talk:Foo as the first result
    When I get api suggestions for template talk:
    Then Template talk:Foo is in the api suggestions

  Scenario Outline: Search suggestions
    When I get api suggestions for <term>
    Then <first_result> is the first api suggestion
      And the api should offer to search for pages containing <term>
    When I get api near matches for <term>
      Then <title> is the first api search result
  Examples:
    | term                   | first_result           | title                  |
# Note that there are more links to catapult then to any other page that starts with the
# word "catapult" so it should be first
    | catapult               | Catapult               | Catapult               |
    | catapul                | Catapult               | none                   |
    | two words              | Two Words              | Two Words              |
    | ~catapult              | none                   | none                   |
    | Template:Template Test | Template:Template Test | Template:Template Test |
    | l'or                   | L'Oréal                | none                   |
    | l or                   | L'Oréal                | none                   |
    | L'orea                 | L'Oréal                | none                   |
    | L'Oréal                | L'Oréal                | L'Oréal                |
    | L’Oréal                | L'Oréal                | L'Oréal                |
    | L Oréal                | L'Oréal                | L'Oréal                |
    | Jean-Yves Le Drian     | Jean-Yves Le Drian     | Jean-Yves Le Drian     |
    | Jean Yves Le Drian     | Jean-Yves Le Drian     | Jean-Yves Le Drian     |

  @redirect
  Scenario: Prefix search includes redirects
    When I get api suggestions for SEO Redirecttest
    Then SEO Redirecttest is the first api suggestion
    When I get api near matches for SEO Redirecttest
    Then SEO Redirecttest is the first api search result

  @redirect
  Scenario: Prefix search includes redirects for pages outside the main namespace
    When I get api suggestions for User_talk:SEO Redirecttest
    Then User talk:SEO Redirecttest is the first api suggestion
    When I get api near matches for User_talk:SEO Redirecttest
    Then User talk:SEO Redirecttest is the first api search result

  @accent_squashing @accented_namespace
  Scenario Outline: Search suggestions with accents
    When I get api suggestions for <term>
    Then <first_result> is the first api suggestion
      And the api should offer to search for pages containing <term>
    When I get api near matches for <term>
    Then <title> is the first api search result
  Examples:
    | term                   | first_result           | title                  |
    | África                 | África                 | África                 |
    | Africa                 | África                 | África                 |
    | AlphaBeta              | AlphaBeta              | AlphaBeta              |
    | ÁlphaBeta              | AlphaBeta              | AlphaBeta              |
    | Mó:Test                | Mó:Test                | Mó:Test                |
    | Mo:Test                | Mó:Test                | Mó:Test                |
    | file:Mo:Test           | none                   | none                   |

  # Just take too long to run on a regular basis
  # @redirect @huge
  # Scenario: Prefix search on pages with tons of redirects is reasonably fast
  #   Given a page named IHaveTonsOfRedirects exists
  #     And there are 1000 redirects to IHaveTonsOfRedirects of the form TonsOfRedirects%s
  #   When I type TonsOfRedirects into the search box
  #   Then suggestions should appear
