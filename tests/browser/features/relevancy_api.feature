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

  # FIXME: find proper settings to make this test pass consistently.
  # The causes are still unclear and mostly related to shard distribution
  # and DFS problems. Unfortunately it's extremely hard to debug because
  # the global context built by DFS is not applied when running in explain
  # mode.
  # Possible cause can be:
  # - a bug in DFS
  # - number of documents, field size or term freq that can vary from time to
  #   time possibly because we generate random docs?
  @expect_failure
  Scenario: Results are sorted based on what part of the page matches: title, redirect, category, etc
    When I api search with query independent profile classic_noboostlinks for Relevancytest
    Then Relevancytest is the first api search result
      And Relevancytestviaredirect is the second api search result
      And Relevancytestviacategory is the third api search result
      And Relevancytestviaheading is the fourth api search result
      And Relevancytestviaopening is the fifth api search result
      And Relevancytestviatext is the sixth or seventh api search result
      And Relevancytestviaauxtext is the sixth or seventh api search result

  # Last two tests use "sixth or seventh" because the current implementation of the all field
  # and the copy_to hack will copy the content only one time for both text and auxiliary_text
  # auxiliary_text is set to 0.5 but will be approximated to 1 (similar to text)
  # phrase freq will be identical for both fields making length norms the sole discriminating
  # criteria.
  Scenario: Results are sorted based on what part of the page matches: title, redirect, category, etc
    When I api search with query independent profile classic_noboostlinks for "Relevancytestphrase phrase"
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

  Scenario: Incoming links count in page weight
    Given a page named Relevancylinktest Smaller exists
      And a page named Relevancylinktest Larger Extraword exists with contents Relevancylinktest needs 5 extra words
      And a page named Relevancylinktest Larger/Link A exists with contents [[Relevancylinktest Larger Extraword]]
      And a page named Relevancylinktest Larger/Link B exists with contents [[Relevancylinktest Larger Extraword]]
      And a page named Relevancylinktest Larger/Link C exists with contents [[Relevancylinktest Larger Extraword]]
      And a page named Relevancylinktest Larger/Link D exists with contents [[Relevancylinktest Larger Extraword]]
    When within 20 seconds api searching for Relevancylinktest -intitle:link yields Relevancylinktest Larger Extraword as the first result and Relevancylinktest Smaller as the second result
      And I api search with query independent profile classic_noboostlinks for Relevancylinktest -intitle:link
    Then Relevancylinktest Smaller is the first api search result
      And Relevancylinktest Larger Extraword is the second api search result
    # This test can fail spuriously for the same reasons that "Redirects count as incoming links" can fail
    # With the allfield Relevancylinktest Smaller will get 21 freq for the term Relevancylinktest and a
    # length norm of 0.125 for the all.plain (title is copied to the text field if no text is set)
    # Relevancylinktest Larger Extraword will get 21 freq for the same term (content being set we re-add
    # "Relevancylinktest" in the content to match the 21 freq of Relevancylinktest Smaller)
    # We add extra words to decrease the length norm to 0.109375.
    # freq 21 is explained by the copy_to features which will copy title words 20 times to the all.plain
    # add one occurrence for the term in the text field and you'll get 21.
    # for norms: Relevancylinktest Smaller will have a term length of 40 + 2 -> 42 which will be computed as
    # 1/sqrt(42) => 0.154 and then encoded as 0.125 (precision reduction)
    # Relevancylinktest Larger Extraword will be 60 + 5 => 65 computed as 0.124 but encoded as 0.109
    # Small java test case to understand:
    # int termCount = 65;
    # TFIDFSimilarity sim = new ClassicSimilarity();
    # FieldInvertState fiv = new FieldInvertState("test", 0, termCount, 0, 0, 1f);
    # System.out.println("computed: " + sim.lengthNorm(fiv));
    # System.out.println("encoded: " + sim.decodeNormValue(sim.computeNorm(fiv)));


  Scenario: Results are sorted based on how close the match is
    When I api search with query independent profile classic_noboostlinks for Relevancyclosetest Foô
    Then Relevancyclosetest Foô is the first api search result
      And Relevancyclosetest Foo is the second api search result
      And Foo Relevancyclosetest is the third api search result

  Scenario: Results are sorted based on how close the match is (backwards this time)
    When I api search with query independent profile classic_noboostlinks for Relevancyclosetest Foo
    Then Relevancyclosetest Foo is the first api search result
      And Relevancyclosetest Foô is the second api search result
      And Foo Relevancyclosetest is the third api search result
