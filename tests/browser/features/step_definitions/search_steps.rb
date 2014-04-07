Given(/^I am at the search results page(?: with the search (.+?)(?: and the prefix (.+))?)?$/) do |search, prefix|
  visit(SearchResultsPage, using_params: {search: search, prefix: prefix})
end
When(/^I go search for (.*)$/) do |search|
  visit(SearchResultsPage, using_params: {search: search})
end

When(/^I type (.+) into the search box$/) do |search_term|
  on(SearchPage).search_input = search_term
end
When(/^I click the search button$/) do
  on(SearchPage).search_button
end
When(/^I search for (.+)$/) do |text|
  #If I'm on the search page then I actually want to search in that box not the
  #one in the upper right.
  page_type = RandomPage
  if ["Search", "Search results"].include?(on(ArticlePage).title) then
    page_type = SearchResultsPage
  end
  on(page_type) do |page|
    if text == "the empty string" then
      page.search_input = ""
      if page.simple_search_button? then
        page.simple_search_button
      else
        page.search_button
      end
    else
      page.search_input = text
      #Sometimes setting the search_input doesn't take so we do it again and again
      #until it does.  I really have no idea why this is the case but users don't
      #seem to have the same problem.
      while page.search_input != text
        page.search_input = text
      end
      if page.simple_search_button? then
        page.simple_search_button
      else
        #Since there isn't a search button on this page we're going to have
        #to use the "containing..." drop down....  We can't even click the
        #search_button because that is a "go" search and we need one that won't
        #drop us directly to the page on a perfect match

        #I have no idea why, but just clicking on the element isn't good enough
        #so we deploy this hack copied from mediawiki.searchSuggest.js
        page.execute_script("$( '\#searchInput' ).closest( 'form' )
          .append( $( '<input type=\"hidden\" name=\"fulltext\" value=\"1\"/>' ) );")
        page.search_button
      end
    end
  end
end
When(/^I click the (.*) link$/) do |text|
  @browser.link(:text => text).click
end
When(/^I click the (.*) label(?:s)?$/) do |text|
  text.split(",").each do |link_text|
    link_text.strip!
    if link_text.include? " or " then
      found = false
      link_text.split(" or ").each do |or_text|
        or_text.strip!
        label = @browser.label(:text => or_text)
        if label.exists? then
          found = true
          label.click
        end
      end
      if !found then
        fail "none of \"" + link_text + "\" could be found"
      end
    else
      @browser.label(:text => link_text).click
    end
  end
end

Then(/^suggestions should( not)? appear$/) do |not_appear|
  if not_appear then
    # Wait to give the element a chance to load if it was going to
    sleep(5)
    on(SearchPage).search_results_element.should_not be_visible
  else
    on(SearchPage).search_results_element.when_present.should be_visible
  end
end
Then(/^(.+) is the first suggestion$/) do |title|
  if title == "none" then
    on(SearchPage).one_result_element.should_not be_visible
  else
    on(SearchPage).one_result.should == title
  end
end
Then(/^(.+) is not in the suggestions$/) do |title|
  on(SearchPage).all_results_elements.each do |result|
    result.text.should_not == title
  end
end
Then(/^I should be offered to search for (.+)$/) do |term|
  on(SearchPage).search_special.should == "containing...\n" + term
end
Then(/^I am on a page titled (.*)$/) do |title|
  on(ArticlePage).title.should == title
end
Then(/^there is a search result$/) do
  on(SearchResultsPage).first_result_element.should exist
end
Then(/^(.+) is( in)? the ([^ ]+) search result$/) do |title, in_ok, index|
  on(SearchResultsPage) do |page|
    check_search_result(page.send("#{index}_result_wrapper_element"),
      page.send("#{index}_result_element"), title, in_ok)
  end
end
Then(/^(.*) is( in)? the first search imageresult$/) do |title, in_ok|
  on(SearchResultsPage) do |page|
    if title == "none" then
      page.first_result_element.should_not exist
      page.first_image_result_element.should_not exist
    else
      page.first_image_result_element.should exist
      if in_ok then
        page.first_image_result_element.text.should include title
      else
        # You can't just use first_image_result.should == because that tries to click the link....
        page.first_image_result_element.text.should == title
      end
    end
  end
end
Then(/^(.*) is the highlighted title of the first search result$/) do |highlighted|
  on(SearchResultsPage).first_result_highlighted_title.should == highlighted
end
Then(/^(.*) is( in)? the highlighted text of the first search result$/) do |highlighted, in_ok|
  if in_ok then
    on(SearchResultsPage).first_result_highlighted_text.should include(highlighted)
  else
    on(SearchResultsPage).first_result_highlighted_text.should == highlighted
  end
end
Then(/^(.*) is the highlighted heading of the first search result$/) do |highlighted|
  if highlighted.empty? then
    on(SearchResultsPage).first_result_heading_wrapper_element.should_not exist
  else
    on(SearchResultsPage).first_result_highlighted_heading.should == highlighted
  end
end
Then(/^(.*) is the highlighted alttitle of the first search result$/) do |highlighted|
  if highlighted.empty? then
    on(SearchResultsPage).first_result_alttitle_wrapper_element.should_not exist
  else
    on(SearchResultsPage).first_result_highlighted_alttitle.should == highlighted
  end
end
Then(/^there is not alttitle on the first search result$/) do
  on(SearchResultsPage).first_result_alttitle_wrapper_element.should_not exist
end
Then(/^(.+) is( not)? in the search results$/) do |title, not_searching|
  found = false
  on(SearchResultsPage).results.each do |result|
    if result.text == title then
      found = true
    end
  end
  if not_searching then
    found.should == false
  else
    found.should == true
  end
end
Then(/^there are no search results$/) do
  on(SearchResultsPage).first_result_element.should_not exist
end
Then(/^within (\d+) seconds searching for (.*) yields (.*) as the first result$/) do |seconds, term, title|
  within(seconds) do
    step("I search for " + term)
    step("#{title} is the first search result")
  end
end
Then(/^within (\d+) seconds typing (.*) into the search box yields (.*) as the first suggestion$/) do |seconds, term, title|
  within(seconds) do
    step("I type #{term} into the search box")
    step("suggestions should appear")
    step("#{title} is the first suggestion")
  end
end
Then(/^there is (no|a)? link to create a new page from the search result$/) do |modifier|
  if modifier == "a" then
    on(SearchResultsPage).create_page_element.should exist
  else
    on(SearchResultsPage).create_page_element.should_not exist
  end
end
Then(/^(.*) is suggested$/) do |text|
  on(SearchResultsPage).highlighted_suggestion.should == text
end
Then(/^there is no suggestion$/) do
  on(SearchResultsPage).suggestion_element.should_not exist
end
Then(/there are no errors reported$/) do
  on(SearchResultsPage).error_report_element.should_not exist
end
Then(/^the title still exists$/) do
  on(ArticlePage).title_element.should exist
end
Then(/^there are( no)? search results with (.+) in the data/) do |should_not_find, within|
  found = false
  on(SearchResultsPage).result_data.each do |result|
    if result.text.include? within then
      found = true
    end
  end
  if should_not_find then
    found.should == false
  else
    found.should == true
  end
end

Then(/^there is no warning$/) do
  on(SearchResultsPage).warning.should == ''
end

def within(seconds)
  end_time = Time.new + Integer(seconds)
  begin
    yield
  rescue => e
    if Time.new > end_time then
      raise e
    else
      @browser.refresh
      retry
    end
  end
end

def check_search_result(wrapper_element, element, title, in_ok)
  if title == "none" then
    wrapper_element.should_not exist
  else
    element.should exist
    if in_ok then
      element.text.should include title
    else
      element.text.should == title
    end
  end
end
