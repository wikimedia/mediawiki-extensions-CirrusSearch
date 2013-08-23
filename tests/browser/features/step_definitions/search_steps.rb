Given(/^I am at the search results page$/) do
  visit SearchResultsPage
end

When(/^I type (.+) into the search box$/) do |search_term|
  on(SearchPage).search_input = search_term
end
When(/^I click the Search button$/) do
  on(RandomPage) do |page|
    if page.search_button? then
      page.search_button
    else
      page.simple_search_button
    end
  end
end
When(/^I hit enter in the search box$/) do
  on(SearchPage).search_input += "\n"
end
When(/^I search for (.+)$/) do |text|
  #If I'm on the search page then I actually want to search in that box not the
  #one in the upper right.
  page_type = RandomPage
  if ['Search', 'Search results'].include?(on(ArticlePage).title) then
    page_type = SearchResultsPage
  end
  on(page_type) do |page|
    if text == 'the empty string' then
      page.search_input = ''
      if page.simple_search_button? then
        page.simple_search_button
      else
        page.search_button
      end
    else
      page.search_input = text
      if page.simple_search_button? then
        page.simple_search_button
      else
        #Since there isn't a simple search button on this page we're going to have
        #to use the "containing..." drop down....
        on(SearchPage).search_special_element.when_present.should exist
        on(SearchPage).search_special_element.click
      end
    end
  end
end
When(/^I click the (.*) link$/) do |text|
  @browser.link(:text => text).click
end
When(/^I click the (.*) label(?:s)?$/) do |text|
  text.split(',').each do |link_text|
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

Then(/^suggestions should appear$/) do
  on(SearchPage).search_results_element.when_present.should exist
end
Then(/^(.+) is the first suggestion$/) do |title|
  if title == 'none' then
    on(SearchPage).one_result_element.should_not exist
  else
    on(SearchPage).one_result.should == title
  end
end
Then(/^I should be offered to search for (.+)$/) do |term|
  on(SearchPage).search_special.should == "containing...\n" + term
end
Then(/^I am on a page titled (.*)$/) do |title|
  on(ArticlePage).title.should == title
end
Then(/^(.*) is( in)? the first search result$/) do |title, in_ok|
  on(SearchResultsPage) do |page|
    if title == 'none' then
      page.first_result_element.should_not exist
      page.first_image_result_element.should_not exist
    else
      page.first_result_element.should exist
      if in_ok then
        page.first_result.should include title
      else
        page.first_result.should == title
      end
    end
  end
end
Then(/^(.*) is( in)? the first search imageresult$/) do |title, in_ok|
  on(SearchResultsPage) do |page|
    if title == 'none' then
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
Then(/^(.*) is the highlighted text of the first search result$/) do |highlighted|
  on(SearchResultsPage).first_result_highlighted_text.should == highlighted
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
    step('I search for ' + term)
    step("#{title} is the first search result")
  end
end
Then(/^within (\d+) seconds typing (.*) into the search box yields (.*) as the first suggestion$/) do |seconds, term, title|
  within(seconds) do
    step("I type #{term} into the search box")
    step('suggestions should appear')
    step("#{title} is the first suggestion")
  end
end
Then(/^(.*) is suggested$/) do |text|
  if text == 'none' then
    on(SearchResultsPage).suggestion_element.should_not exist
  else
    on(SearchResultsPage).suggestion.should == text
  end
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