@clean @api @relevancy
Feature: Results are ordered from most relevant to least.
  Scenario: Words in order are worth more then words out of order
    When I api search for Relevancytwo Wordtest
    Then Relevancytwo Wordtest is the first api search result
      And Wordtest Relevancytwo is the second api search result

  Scenario: Results are sorted based on namespace: main, talk, file, help, file talk, etc
    When I api search for all:Relevancynamespacetest
    Then Relevancynamespacetest is the first api search result
      And Talk:Relevancynamespacetest is the second api search result
      And File:Relevancynamespacetest is the third api search result
      And Help:Relevancynamespacetest is the fourth api search result
      And File talk:Relevancynamespacetest is the fifth api search result
      And User talk:Relevancynamespacetest is the sixth api search result
      And Template:Relevancynamespacetest is the seventh api search result

  Scenario: When the user doesn't set a language are sorted with wiki language ahead of other languages
    When I api search for Relevancylanguagetest
    Then Relevancylanguagetest/en is the first api search result

  Scenario: Redirects count as incoming links
    When I api search for Relevancyredirecttest
    Then Relevancyredirecttest Larger is the first api search result
      And Relevancyredirecttest Smaller is the second api search result
