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

  # The stub highlights the first sentence of each passage. Without the
  # semantic_highlighting_batch ext the search fails.
  Scenario Outline: The hl debug option highlights the passage of the first result
    When I api search semantically with highlights for <query>
    Then there are no errors reported by the api
      And <title> is the first api search result
      And <snippet> is the highlighted snippet of the first api search result
  Examples:
    | query                                                                                     | title                     | snippet                                                                                     |
    | quorvex blintharm zephulon drabbit mivvle sporkanth tellurine glomp frobnitz              | Semantic Fixture Quorvex  | *Quorvex blintharm zephulon drabbit.* Mivvle sporkanth tellurine glomp.                     |
    | žarnovik 𐌷𐌰𐌿𐍃 qlembrisk 漢字 velthorn dravquill snorbex wimplethane frusk ölgrim | Semantic Fixture Velthorn | *Žarnovik 𐌷𐌰𐌿𐍃 qlembrisk 漢字 velthorn 😀 dravquill.* Snorbex wimplethane frusk ölgrim. |

  # Without hl the builder profile decides if the passage is highlighted. The
  # snippet compare is exact, so any highlight fails the semantic_no_hl row.
  Scenario Outline: The <profile> builder profile decides the semantic highlight
    When I api search semantically using the <profile> builder profile for quorvex blintharm zephulon drabbit mivvle sporkanth tellurine glomp frobnitz
    Then there are no errors reported by the api
      And Semantic Fixture Quorvex is the first api search result
      And <snippet> is the highlighted snippet of the first api search result
  Examples:
    | profile        | snippet                                                                 |
    | semantic_hl    | *Quorvex blintharm zephulon drabbit.* Mivvle sporkanth tellurine glomp. |
    | semantic_no_hl | Quorvex blintharm zephulon drabbit. Mivvle sporkanth tellurine glomp.   |
