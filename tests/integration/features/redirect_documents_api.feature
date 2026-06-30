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
