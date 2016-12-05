#
# This file is subject to the license terms in the COPYING file found in the
# CirrusSearch top-level directory and at
# https://git.wikimedia.org/blob/mediawiki%2Fextensions%2FCirrusSearch/HEAD/COPYING. No part of
# CirrusSearch, including this file, may be copied, modified, propagated, or
# distributed except according to the terms contained in the COPYING file.
#
# Copyright 2012-2014 by the Mediawiki developers. See the CREDITS file in the
# CirrusSearch top-level directory and at
# https://git.wikimedia.org/blob/mediawiki%2Fextensions%2FCirrusSearch/HEAD/CREDITS
#
When(/^I search for: (.+)$/) do |search_term|
  on(SearchPage).search_input_element.when_present.send_keys(search_term)
end

Then(/^a list of suggested pages should appear$/) do
  with_browser do
    on(SearchPage).search_results_element.when_present.should exist
  end
end
Then(/^I should land on Search Results page$/) do
  with_browser do
    on(SearchResultsPage).search_element.when_present
    browser.url.should match Regexp.escape("&title=Special%3ASearch")
  end
end
Then(/^(.+) should be the first result$/) do |page_name|
  with_browser do
    on(SearchPage).first_result.should == page_name
  end
end

Then(/^the page I arrive on has title (.+)$/) do |title|
  with_browser do
    browser.title.should match Regexp.escape(title)
  end
end
