@api @redirect @redirect_documents @update
Feature: First-class redirect documents on the edit path
  # These scenarios require CirrusSearchRedirectDocuments['build'] = true and a live index.
  # They are NOT exercised by run-unit-tests.sh (which has no OpenSearch).

  Scenario: Editing a redirect page produces a redirect document and refreshes the target's redirect array
    Given a page named RedirDocTarget exists
     When a page named RedirDocAlpha%{epoch} exists with contents #REDIRECT [[RedirDocTarget]]
      And I wait for the redirect document RedirDocAlpha%{epoch} to have page_type redirect
      And I wait for the redirect document RedirDocAlpha%{epoch} to have redirect_target title RedirDocTarget
      And I wait for RedirDocTarget to include RedirDocAlpha%{epoch} in redirect

  Scenario: Converting an ordinary page into a redirect hides it from standard search
    Given a page named RedirDocTarget exists
     When a page named RedirDocBeta%{epoch} exists
      And I api search for RedirDocBeta%{epoch}
     Then RedirDocBeta%{epoch} is the first api search result
     When a page named RedirDocBeta%{epoch} exists with contents #REDIRECT [[RedirDocTarget]]
      And I wait for the redirect document RedirDocBeta%{epoch} to have page_type redirect
      And I api search for RedirDocBeta%{epoch}
     Then RedirDocBeta%{epoch} is not in the api search results

  Scenario: Converting a redirect back into an ordinary page makes it findable with page_type primary
    Given a page named RedirDocTarget exists
     When a page named RedirDocGamma%{epoch} exists with contents #REDIRECT [[RedirDocTarget]]
      And I wait for the redirect document RedirDocGamma%{epoch} to have page_type redirect
     When a page named RedirDocGamma%{epoch} exists
      And I wait for RedirDocGamma%{epoch} to have page_type of primary
      And I wait for RedirDocGamma%{epoch} to not have a redirect_target
      And I wait for RedirDocTarget to not have RedirDocGamma%{epoch} in redirect

  # These scenarios target Two Words and its Rdir, Crazy Rdir and Insane Rdir
  # redirects. Crazy and Insane are single title tokens unique to one redirect
  # each, so requiring both matches neither redirect document (and, in redirect
  # mode, not the target either).
  Scenario: intitle: does not cross-match across distinct redirects in redirect mode
     When I api search for withredirects: intitle:Crazy
     Then Crazy Rdir is in the api search results
     When I api search for withredirects: intitle:Crazy intitle:Insane
     Then Two Words is not in the api search results
      And Crazy Rdir is not in the api search results
      And Insane Rdir is not in the api search results

  @regex
  Scenario: intitle:// regex does not cross-match across distinct redirects in redirect mode
     When I api search for withredirects: intitle:/Crazy/
     Then Crazy Rdir is in the api search results
     When I api search for withredirects: intitle:/Crazy/ intitle:/Insane/
     Then Two Words is not in the api search results
      And Crazy Rdir is not in the api search results
      And Insane Rdir is not in the api search results

  # Rdir shares no title tokens with its target Two Words, so the redirect match produces a
  # redirectsnippet (not pre-empted by a title snippet), making suppression observable.
  Scenario: standard search shows the redirectTitle on a primary result
     When I api search for Rdir
     Then Two Words is in the api search results
      And Two Words has redirectsnippet in the api search results

  Scenario: withredirects: suppresses the redirectTitle on a primary result
     When I api search for withredirects: Rdir
     Then Two Words is in the api search results
      And Two Words has no redirectsnippet in the api search results

  @regex
  Scenario: withredirects: suppresses the redirectTitle on a primary result in the regex environment
     When I api search for withredirects: Rdir
     Then Two Words is in the api search results
      And Two Words has no redirectsnippet in the api search results
