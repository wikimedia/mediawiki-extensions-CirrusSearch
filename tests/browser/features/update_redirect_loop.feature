Feature: Search backend updates containing redirect loops
  Background:
    Given I am logged in

  @redirect_loop
  Scenario: Pages that redirect to themself don't throw errors
    When a page named IAmABad RedirectSelf%{epoch} exists with contents #REDIRECT [[IAmABad RedirectSelf%{epoch}]]
    Then I am on a page titled IAmABad RedirectSelf%{epoch}

  @redirect_loop
  Scenario: Pages that form a redirect chain don't throw errors
    When a page named IAmABad RedirectChain%{epoch} A exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} B]]
    And a page named IAmABad RedirectChain%{epoch} B exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} C]]
    And a page named IAmABad RedirectChain%{epoch} C exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} D]]
    And a page named IAmABad RedirectChain%{epoch} D exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} A]]
    Then I am on a page titled IAmABad RedirectChain%{epoch} D
    When a page named IAmABad RedirectChain%{epoch} B exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} D]]
    Then I am on a page titled IAmABad RedirectChain%{epoch} B
