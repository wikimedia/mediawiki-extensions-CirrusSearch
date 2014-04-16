@clean @phantomjs
Feature: Page updates trigger appropriate weight updates in newly linked and unlinked articles
  # Note that these tests can be a bit flakey if you don't use Redis and checkDelay because they count using
  # Elasticsearch which delays all updates for around a second.  So if the jobs run too fast they won't work.
  # Redis and checkDelay fix this by forcing a delay.
  Scenario: Pages weights are updated when new pages link to them
    Given a page named WeightedLink%{epoch} 1 exists
    And a page named WeightedLink%{epoch} 2/1 exists with contents [[WeightedLink%{epoch} 2]]
    And a page named WeightedLink%{epoch} 2 exists
    When I am at a random page
    Then within 20 seconds searching for WeightedLink%{epoch} yields WeightedLink%{epoch} 2 as the first result
    When a page named WeightedLink%{epoch} 1/1 exists with contents [[WeightedLink%{epoch} 1]]
    And a page named WeightedLink%{epoch} 1/2 exists with contents [[WeightedLink%{epoch} 1]]
    Then within 20 seconds searching for WeightedLink%{epoch} yields WeightedLink%{epoch} 1 as the first result

  Scenario: Pages weights are updated when links are removed from them
    Given a page named WeightedLinkRemoveUpdate%{epoch} 1/1 exists with contents [[WeightedLinkRemoveUpdate%{epoch} 1]]
    And a page named WeightedLinkRemoveUpdate%{epoch} 1/2 exists with contents [[WeightedLinkRemoveUpdate%{epoch} 1]]
    And a page named WeightedLinkRemoveUpdate%{epoch} 1 exists
    And a page named WeightedLinkRemoveUpdate%{epoch} 2/1 exists with contents [[WeightedLinkRemoveUpdate%{epoch} 2]]
    And a page named WeightedLinkRemoveUpdate%{epoch} 2 exists
    When I am at a random page
    Then within 20 seconds searching for WeightedLinkRemoveUpdate%{epoch} yields WeightedLinkRemoveUpdate%{epoch} 1 as the first result
    When a page named WeightedLinkRemoveUpdate%{epoch} 1/1 exists with contents [[Junk]]
    And a page named WeightedLinkRemoveUpdate%{epoch} 1/2 exists with contents [[Junk]]
    Then within 20 seconds searching for WeightedLinkRemoveUpdate%{epoch} yields WeightedLinkRemoveUpdate%{epoch} 2 as the first result

  Scenario: Pages weights are updated when new pages link to their redirects
    Given a page named WeightedLinkRdir%{epoch} 1/Redirect exists with contents #REDIRECT [[WeightedLinkRdir%{epoch} 1]]
    And a page named WeightedLinkRdir%{epoch} 1 exists
    And a page named WeightedLinkRdir%{epoch} 2/Redirect exists with contents #REDIRECT [[WeightedLinkRdir%{epoch} 2]]
    And a page named WeightedLinkRdir%{epoch} 2/1 exists with contents [[WeightedLinkRdir%{epoch} 2/Redirect]]
    And a page named WeightedLinkRdir%{epoch} 2 exists
    When I am at a random page
    Then within 20 seconds searching for WeightedLinkRdir%{epoch} yields WeightedLinkRdir%{epoch} 2 as the first result
    When a page named WeightedLinkRdir%{epoch} 1/1 exists with contents [[WeightedLinkRdir%{epoch} 1/Redirect]]
    And a page named WeightedLinkRdir%{epoch} 1/2 exists with contents [[WeightedLinkRdir%{epoch} 1/Redirect]]
    Then within 20 seconds searching for WeightedLinkRdir%{epoch} yields WeightedLinkRdir%{epoch} 1 as the first result

  Scenario: Pages weights are updated when links are removed from their redirects
    Given a page named WLRURdir%{epoch} 1/1 exists with contents [[WLRURdir%{epoch} 1/Redirect]]
    And a page named WLRURdir%{epoch} 1/2 exists with contents [[WLRURdir%{epoch} 1/Redirect]]
    And a page named WLRURdir%{epoch} 1/Redirect exists with contents #REDIRECT [[WLRURdir%{epoch} 1]]
    And a page named WLRURdir%{epoch} 1 exists
    And a page named WLRURdir%{epoch} 2/Redirect exists with contents #REDIRECT [[WLRURdir%{epoch} 2]]
    And a page named WLRURdir%{epoch} 2/1 exists with contents [[WLRURdir%{epoch} 2/Redirect]]
    And a page named WLRURdir%{epoch} 2 exists
    When I am at a random page
    Then within 20 seconds searching for WLRURdir%{epoch} yields WLRURdir%{epoch} 1 as the first result
    When a page named WLRURdir%{epoch} 1/1 exists with contents [[Junk]]
    And a page named WLRURdir%{epoch} 1/2 exists with contents [[Junk]]
    Then within 20 seconds searching for WLRURdir%{epoch} yields WLRURdir%{epoch} 2 as the first result

  Scenario: Redirects to redirects don't count in the score
    Given a page named WLDoubleRdir%{epoch} 1/Redirect exists with contents #REDIRECT [[WLDoubleRdir%{epoch} 1]]
    And a page named WLDoubleRdir%{epoch} 1/Redirect Redirect exists with contents #REDIRECT [[WLDoubleRdir%{epoch} 1/Redirect]]
    And a page named WLDoubleRdir%{epoch} 1/1 exists with contents [[WLDoubleRdir%{epoch} 1/Redirect Redirect]]
    And a page named WLDoubleRdir%{epoch} 1/2 exists with contents [[WLDoubleRdir%{epoch} 1/Redirect Redirect]]
    And a page named WLDoubleRdir%{epoch} 1 exists
    And a page named WLDoubleRdir%{epoch} 2/Redirect exists with contents #REDIRECT [[WLDoubleRdir%{epoch} 2]]
    And a page named WLDoubleRdir%{epoch} 2/1 exists with contents [[WLDoubleRdir%{epoch} 2/Redirect]]
    And a page named WLDoubleRdir%{epoch} 2/2 exists with contents [[WLDoubleRdir%{epoch} 2/Redirect]]
    And a page named WLDoubleRdir%{epoch} 2 exists
    When I am at a random page
    Then within 20 seconds searching for WLDoubleRdir%{epoch} yields WLDoubleRdir%{epoch} 2 as the first result
