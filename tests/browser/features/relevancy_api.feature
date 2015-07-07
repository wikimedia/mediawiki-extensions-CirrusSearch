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
    Given a page named Relevancyredirecttest Smaller exists with contents Relevancyredirecttest A text text text text text text text text text text text text text
      And a page named Relevancyredirecttest Smaller/A exists with contents [[Relevancyredirecttest Smaller]]
      And a page named Relevancyredirecttest Smaller/B exists with contents [[Relevancyredirecttest Smaller]]
      And a page named Relevancyredirecttest Larger exists with contents Relevancyredirecttest B text text text text text text text text text text text text text
      And a page named Relevancyredirecttest Larger/Redirect exists with contents #REDIRECT [[Relevancyredirecttest Larger]]
      And a page named Relevancyredirecttest Larger/A exists with contents [[Relevancyredirecttest Larger]]
      And a page named Relevancyredirecttest Larger/B exists with contents [[Relevancyredirecttest Larger/Redirect]]
      And a page named Relevancyredirecttest Larger/C exists with contents [[Relevancyredirecttest Larger/Redirect]]
    Then within 20 seconds api searching for Relevancyredirecttest yields Relevancyredirecttest Larger as the first result and Relevancyredirecttest Smaller as the second result
    # Note that this test can fail spuriously in two ways:
    # 1. If the required pages are created as part of the hook for @relevancy its quite possible for the large influx
    # of jobs to cause the counting jobs to not pick up all the counts. I'm not super sure why that is but moving the
    # creation into its own section makes it pretty consistent.
    # 2. Its quite possible for the second result to be deeper in the result list for a few seconds after the pages are
    # created. It gets its position updated by the link counting job which has to wait for refreshing and undelaying.

  Scenario: Results are sorted based on what part of the page matches: title, redirect, category, etc
    When I api search with disabled incoming link weighting for Relevancytest
    Then Relevancytest is the first api search result
      And Relevancytestviaredirect is the second api search result
      And Relevancytestviacategory is the third api search result
      And Relevancytestviaheading is the fourth api search result
      And Relevancytestviaopening is the fifth api search result
      And Relevancytestviatext is the sixth or seventh api search result
      And Relevancytestviaauxtext is the sixth or seventh api search result

  Scenario: Results are sorted based on what part of the page matches: title, redirect, category, etc
    When I api search with disabled incoming link weighting for "Relevancytestphrase phrase"
    Then Relevancytestphrase phrase is the first api search result
      And Relevancytestphraseviaredirect is the second api search result
      And Relevancytestphraseviacategory is the third api search result
      And Relevancytestphraseviaheading is the fourth api search result
      And Relevancytestphraseviaopening is the fifth api search result
      And Relevancytestphraseviatext is the sixth or seventh api search result
      And Relevancytestphraseviaauxtext is the sixth or seventh api search result

  Scenario: When the user has a language results are sorted with user language ahead of wiki language ahead of other languages
    When I api search in the ja language for Relevancylanguagetest
    Then Relevancylanguagetest/ja is the first api search result
      And Relevancylanguagetest/en is the second api search result
      And Relevancylanguagetest/ar is the third api search result

  @expect_failure
  Scenario: Incoming links count in page weight
    When I api search for Relevancylinktest -intitle:link
    Then Relevancylinktest Larger Extraword is the first api search result
      And Relevancylinktest Smaller is the second api search result
    When I api search with disabled incoming link weighting for Relevancylinktest -intitle:link
    Then Relevancylinktest Smaller is the first api search result
      And Relevancylinktest Larger Extraword is the second api search result

  Scenario: Results are sorted based on how close the match is
    When I api search with disabled incoming link weighting for Relevancyclosetest Foô
    Then Relevancyclosetest Foô is the first api search result
      And Relevancyclosetest Foo is the second api search result
      And Foo Relevancyclosetest is the third api search result

  Scenario: Results are sorted based on how close the match is (backwards this time)
    When I api search with disabled incoming link weighting for Relevancyclosetest Foo
    Then Relevancyclosetest Foo is the first api search result
      And Relevancyclosetest Foô is the second api search result
      And Foo Relevancyclosetest is the third api search result
