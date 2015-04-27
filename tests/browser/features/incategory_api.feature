@clean @filters @incategory @api
Feature: Searches with the incategory filter
  Scenario: incategory: works on categories from templates
    When I api search for incategory:templatetagged incategory:twowords
    Then Two Words is the first api search result

  Scenario: incategory works with multi word categories
    When I api search for incategory:"Categorywith Twowords"
    Then Two Words is the first api search result

  Scenario: incategory can find categories containing quotes if the quote is escaped
    When I api search for incategory:"Categorywith \" Quote"
    Then Two Words is the first api search result

  Scenario: incategory can be repeated
    When I api search for incategory:"Categorywith \" Quote" incategory:"Categorywith Twowords"
    Then Two Words is the first api search result

  Scenario: incategory works with can find two word categories with spaces
    When I api search for incategory:Categorywith_Twowords
    Then Two Words is the first api search result

  Scenario: incategory: when passed a quoted category that doesn't exist finds nothing even though there is a category that matches one of the words
    When I api search for incategory:"Dontfindme Weaponry"
    Then there are no api search results

  Scenario: incategory when passed a single word category doesn't find a two word category that contains that word
    When I api search for incategory:ASpace
    Then there are no api search results

  Scenario: incategory: finds a multiword category when it is surrounded by quotes
    When I api search for incategory:"CategoryWith ASpace"
    Then IHaveATwoWordCategory is the first api search result
