@setup_main @api @cirrus_explain_page
Feature: cirrusExplainPage debug option
  # Dump-and-die debug option: runs the
  # production query for the request, then instead of executing the search
  # issues a single-document _explain for one page and returns the blob
  # { found, matched, explanation, query, index, docId }. Requires a live index.

  Scenario: An indexed page matching the query is found and matched, with the raw explanation and rendered query
    When I request an explain for page Catapult with query catapult
    Then the explain result reports the page as found
      And the explain result reports the page as matched
      And the explain result includes a raw explanation
      And the explain result includes the rendered query
      And the explain result echoes the index and doc id of Catapult

  Scenario: A page whose namespace is outside the search scope is found but does not match
    # Routing uses the page's own namespace (NS 0), so the doc is found; the query
    # restricts to namespace 2, so the namespace filter clause excludes it.
    When I request an explain for page Catapult with query catapult in namespace 2
    Then the explain result reports the page as found
      And the explain result reports the page as not matched
      And the explain result explanation references the namespace field

  Scenario: An unresolvable page id is reported as not found
    When I request an explain for the unknown page id 999999999 with query catapult
    Then the explain result reports the page as not found
