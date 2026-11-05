@semantic @api
Feature: Semantic search
  # Needs a 3.x cluster with the ml-commons setup and the LiftWing stub from the
  # integration test runner. The runner config leaves @semantic out on other images.
  # The fixture pages come from the shared corpus.

  Scenario: A semantic search finds the page that shares most of its words
    # The page doesn't have frobnitz, so a lexical search would find nothing.
    When I api search semantically for quorvex blintharm zephulon drabbit mivvle sporkanth tellurine glomp frobnitz
    Then there are no errors reported by the api
      And Semantic Fixture Quorvex is the first api search result
