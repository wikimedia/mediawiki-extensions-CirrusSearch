@clean @api @prefix
Feature: Prefix search via api
  Scenario: Suggestions don't appear when you search for a string that is too long
    When I get api suggestions for 贵州省瞬时速度团头鲂身体c实施ysstsstsg说tyttxy以推销员会同香港推广系统在同他讨厌她团体淘汰>赛系统大选于它拥有一天天用于与体育学院国ttxzyttxtxytdttyyyztdsytstsstxtttd天天体育系统的摄像头听到他他偷笑>偷笑太阳团体杏眼桃腮他要tttxx y
    Then none is the first api suggestion

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

