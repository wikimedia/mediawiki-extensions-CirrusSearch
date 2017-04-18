#
# This file is subject to the license terms in the COPYING file found in the
# CirrusSearch top-level directory and at
# https://phabricator.wikimedia.org/diffusion/ECIR/browse/master/COPYING. No part of
# CirrusSearch, including this file, may be copied, modified, propagated, or
# distributed except according to the terms contained in the COPYING file.
#
# Copyright 2012-2014 by the Mediawiki developers. See the CREDITS file in the
# CirrusSearch top-level directory and at
# https://phabricator.wikimedia.org/diffusion/ECIR/browse/master/CREDITS
#
Then(/^I should land on Search Results page$/) do
  with_browser do
    on(SearchResultsPage).search_element.when_present
    browser.url.should match Regexp.escape("&title=Special%3ASearch")
  end
end

Then(/^the page I arrive on has title (.+)$/) do |title|
  with_browser do
    browser.title.should match Regexp.escape(title)
  end
end
