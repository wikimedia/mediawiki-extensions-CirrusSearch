@clean @phantomjs @redirect_loop
Feature: Search backend updates containing redirect loops
  Scenario: Pages that redirect to themself don't throw errors
    Then a page named IAmABad RedirectSelf%{epoch} exists with contents #REDIRECT [[IAmABad RedirectSelf%{epoch}]]

  # The actual creation of the pages will fails if redirect loops fails
  Scenario: Pages that form a redirect chain don't throw errors
    When a page named IAmABad RedirectChain%{epoch} A exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} B]]
    And a page named IAmABad RedirectChain%{epoch} B exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} C]]
    And a page named IAmABad RedirectChain%{epoch} C exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} D]]
    Then a page named IAmABad RedirectChain%{epoch} D exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} A]]
    And a page named IAmABad RedirectChain%{epoch} B exists with contents #REDIRECT [[IAmABad RedirectChain%{epoch} D]]
