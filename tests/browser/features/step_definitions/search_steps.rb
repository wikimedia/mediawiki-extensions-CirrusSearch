require "cgi"

Given(/^I am at the search results page(?: with the search (.+?)(?: and the prefix (.+))?)?$/) do |search, prefix|
  visit(SearchResultsPage, using_params: { search: search, prefix: prefix })
end
When(/^I go search for (.*)$/) do |search|
  visit(SearchResultsPage, using_params: { search: search })
end
When(/^I api search( with disabled incoming link weighting)?(?: with offset (\d+))?(?: in the (.*) language)? for (.*)$/) do |incoming_links, offset, lang, search|
  begin
    @api_result = search_for(
      search,
      sroffset: offset,
      uselang: lang,
      cirrusBoostLinks: incoming_links ? "no" : "yes"
    )
  rescue MediawikiApi::ApiError => e
    @api_error = e
  end
end
When(/^I get api suggestions for (.*)$/) do |search|
  begin
    @api_result = suggestions_for(search)
  rescue MediawikiApi::ApiError => e
    @api_error = e
  end
end
When(/^I type (.+) into the search box$/) do |search_term|
  on(SearchPage).search_input = search_term
end
When(/^I click the search button$/) do
  on(SearchPage).search_button
end
When(/^I search for (.+)$/) do |text|
  # If I'm on the search page then I actually want to search in that box not the
  # one in the upper right.
  page_type = RandomPage
  page_type = SearchResultsPage if ["Search", "Search results"].include?(on(ArticlePage).title)
  on(page_type) do |page|
    if text == "the empty string"
      page.search_input = ""
      if page.simple_search_button?
        page.simple_search_button
      else
        page.search_button
      end
    else
      page.search_input = text
      # Sometimes setting the search_input doesn't take so we do it again and again
      # until it does.  I really have no idea why this is the case but users don't
      # seem to have the same problem.
      page.search_input = text while page.search_input != text
      if page.simple_search_button?
        page.simple_search_button
      else
        # Since there isn't a search button on this page we're going to have
        # to use the "containing..." drop down....  We can't even click the
        # search_button because that is a "go" search and we need one that won't
        # drop us directly to the page on a perfect match

        # I have no idea why, but just clicking on the element isn't good enough
        # so we deploy this hack copied from mediawiki.searchSuggest.js
        page.execute_script("$( '\#searchInput' ).closest( 'form' )
          .append( $( '<input type=\"hidden\" name=\"fulltext\" value=\"1\"/>' ) );")
        page.search_button
      end
    end
  end
end
When(/^I switch the language to (.+)$/) do |language|
  @browser.goto("#{@browser.url}&uselang=#{language}")
end
When(/^I disable incoming links in the weighting$/) do
  @browser.goto("#{@browser.url}&cirrusBoostLinks=no")
end
When(/^I jump to offset (.+)$/) do |offset|
  @browser.goto("#{@browser.url}&offset=#{offset}")
end
When(/^I click the (.*) link$/) do |text|
  @browser.link(text: text).click
end
When(/^I click the (.*) label(?:s)?$/) do |text|
  text.split(",").each do |link_text|
    link_text.strip!
    if link_text.include? " or "
      found = false
      link_text.split(" or ").each do |or_text|
        or_text.strip!
        label = @browser.label(text: or_text)
        if label.exists?
          found = true
          label.click
        end
      end
      fail "none of \"#{link_text}\" could be found" unless found
    else
      @browser.label(text: link_text).click
    end
  end
end
When(/^I dump the cirrus data for (.+)$/) do |title|
  visit(CirrusDumpPage, using_params: { page_name: title })
end
When(/^I request a dump of the query$/) do
  @browser.goto("#{@browser.url}&cirrusDumpQuery=yes")
end
When(/^I dump the cirrus config$/) do
  visit(CirrusConfigDumpPage)
end
When(/^I dump the cirrus mapping$/) do
  visit(CirrusMappingDumpPage)
end
When(/^I dump the cirrus settings$/) do
  visit(CirrusSettingsDumpPage)
end

Then(/^suggestions should( not)? appear$/) do |not_appear|
  if not_appear
    # Wait to give the element a chance to load if it was going to
    sleep(5)
    on(SearchPage).search_results_element.should_not be_visible
  else
    on(SearchPage).search_results_element.when_present.should be_visible
  end
end
Then(/^(.+) is the first suggestion$/) do |title|
  if title == "none"
    on(SearchPage).first_result_element.should_not be_visible
  else
    on(SearchPage).first_result.should == title
  end
end
Then(/^(.+) is the (.+) api suggestion$/) do |title, position|
  pos = %w(first second third fourth fifth sixth seventh eighth ninth tenth).index position
  if title == "none"
    if @api_error
      true
    else
      @api_result[1].length.should_be <= index
    end
  else
    @api_result[1].length.should be > pos
    @api_result[1][pos].should be == title
  end
end
Then(/^(.+) is the second suggestion$/) do |title|
  if title == "none"
    on(SearchPage).second_result_element.should_not be_visible
  else
    on(SearchPage).second_result.should == title
  end
end
Then(/^(.+) is( not)? in the suggestions$/) do |title, should_not|
  found = false
  on(SearchPage).all_results_elements.each do |result|
    if should_not
      result.text.should_not == title
    else
      found |= result.text == title
    end
  end
  found.should == true unless should_not
end
Then(/^(.+) is( not)? in the api suggestions$/) do |title, should_not|
  found = false
  @api_result[1].each do |result|
    if should_not
      result.should_not == title
    else
      found |= result == title
    end
  end
  found.should == true unless should_not
end
Then(/^I should be offered to search for (.+)$/) do |term|
  on(SearchPage).search_special.should == "containing...\n" + term
end
Then(/^there is a search result$/) do
  on(SearchResultsPage).first_result_element.should exist
end
Then(/^there is an api search result$/) do
  @api_result["search"].length.should_not == 0
end
Then(/^(.+) is( in)? the ((?:[^ ])+(?: or (?:[^ ])+)*) search result$/) do |title, in_ok, indexes|
  on(SearchResultsPage) do |page|
    found = indexes.split(/ or /).any? do |index|
      begin
        check_search_result(
          page.send("#{index}_result_wrapper_element"),
          page.send("#{index}_result_element"),
          title,
          in_ok)
        true
      rescue
        false
      end
    end
    found.should == true
  end
end
Then(/^(.+) is( in)? the ([^ ]+) api search result$/) do |title, in_ok, index|
  pos = %w(first second third fourth fifth sixth seventh eighth ninth tenth).index index
  check_api_search_result(
    @api_result["search"].length > pos ? @api_result["search"][pos] : {},
    title,
    in_ok)
end
Then(/^(.+) is( in)? the ((?:[^ ])+(?: or (?:[^ ])+)+) api search result$/) do |title, in_ok, indexes|
  found = indexes.split(/ or /).any? do |index|
    begin
      pos = %w(first second third fourth fifth sixth seventh eighth ninth tenth).index index
      check_api_search_result(
        @api_result["search"].length > pos ? @api_result["search"][pos] : {},
        title,
        in_ok)
      true
    rescue
      false
    end
  end
  found.should == true
end
Then(/^(.*) is( in)? the first search imageresult$/) do |title, in_ok|
  on(SearchResultsPage) do |page|
    if title == "none"
      page.first_result_element.should_not exist
      page.first_image_result_element.should_not exist
    else
      page.first_image_result_element.should exist
      if in_ok
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
  if in_ok
    on(SearchResultsPage).first_result_highlighted_text.should include(highlighted)
  else
    on(SearchResultsPage).first_result_highlighted_text.should == highlighted
  end
end
Then(/^(.*) is the highlighted heading of the first search result$/) do |highlighted|
  if highlighted.empty?
    on(SearchResultsPage).first_result_heading_wrapper_element.should_not exist
  else
    on(SearchResultsPage).first_result_highlighted_heading.should == highlighted
  end
end
Then(/^(.*) is the highlighted alttitle of the first search result$/) do |highlighted|
  if highlighted.empty?
    on(SearchResultsPage).first_result_alttitle_wrapper_element.should_not exist
  else
    on(SearchResultsPage).first_result_highlighted_alttitle.should == highlighted
  end
end
Then(/^(.*) is( in)? the highlighted (.*) of the first api search result$/) do |highlighted, in_ok, key|
  key = "titlesnippet" if key == "title" && !highlighted.index("*").nil?
  check_api_highlight(key, 0, highlighted, in_ok)
end
Then(/^the first api search result is a match to file content$/) do
  @api_result["search"][0]["isfilematch"].should be true
end
Then(/^there is not alttitle on the first search result$/) do
  on(SearchResultsPage).first_result_alttitle_wrapper_element.should_not exist
end
Then(/^(.+) is( not)? in the api search results$/) do |title, not_searching|
  check_all_api_search_results(title, not_searching, false)
end
Then(/^(.+) is( not)? in the search results$/) do |title, not_searching|
  check_all_search_results(title, not_searching, false)
end
Then(/^(.+) is( not)? part of the api search result$/) do |title, not_searching|
  check_all_api_search_results(title, not_searching, true)
end
Then(/^(.+) is( not)? part of a search result$/) do |title, not_searching|
  check_all_search_results(title, not_searching, true)
end
Then(/^there are no search results$/) do
  on(SearchResultsPage).first_result_element.should_not exist
end
Then(/^there are no api search results$/) do
  @api_result["search"].length.should == 0
end
Then(/^there are (\d+) search results$/) do |results|
  on(SearchResultsPage).search_results_element.items.should == results.to_i
end
Then(/^there are (\d+) api search results$/) do |results|
  @api_result["search"].length.should == results.to_i
end
Then(/^within (\d+) seconds searching for (.*) yields (.*) as the first result$/) do |seconds, term, title|
  within(seconds) do
    step("I search for " + term)
    step("#{title} is the first search result")
  end
end
Then(/^within (\d+) seconds api searching for (.*) yields (.*) as the first result$/) do |seconds, term, title|
  repeat_within(seconds) do
    step("I api search for " + term)
    step("#{title} is the first api search result")
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
  if modifier == "a"
    on(SearchResultsPage).create_page_element.should exist
  else
    on(SearchResultsPage).create_page_element.should_not exist
  end
end
Then(/^(.*) is suggested by api$/) do |text|
  fixed = @api_result["searchinfo"]["suggestionsnippet"].gsub(/<em>(.*?)<\/em>/, '*\1*')
  fixed.should == CGI.escapeHTML(text)
end
Then(/^(.*) is suggested$/) do |text|
  on(SearchResultsPage).highlighted_suggestion.should == text
end
Then(/^there is no api suggestion$/) do
  @api_result["searchinfo"]["suggestion"].should be_nil
end
Then(/^there is no suggestion$/) do
  on(SearchResultsPage).suggestion_element.should_not exist
end
Then(/there are no errors reported$/) do
  on(SearchResultsPage).error_report_element.should_not exist
end
Then(/this error is reported: (.+)$/) do |expected_error|
  on(SearchResultsPage).error_report_element.text.strip.should == expected_error.strip
end
Then(/this error is reported by api: (.+)$/) do |expected_error|
  @api_error.code.should be == "srsearch-error"
  require "cgi"
  CGI.unescapeHTML(@api_error.info).should == expected_error.strip
end

Then(/^the title still exists$/) do
  on(ArticlePage).title_element.should exist
end
Then(/^there are( no)? search results with (.+) in the data/) do |should_not_find, within|
  found = false
  on(SearchResultsPage).result_data.each do |result|
    found ||= result.text.include? within
  end
  if should_not_find
    found.should == false
  else
    found.should == true
  end
end
Then(/^there are( no)? api search results with (.+) in the data/) do |should_not_find, within|
  found = false
  @api_result["search"].any? do |result|
    found ||= result["snippet"].include? within
  end
  if should_not_find
    found.should == false
  else
    found.should == true
  end
end
Then(/^there is no warning$/) do
  on(SearchResultsPage).warning.should == ""
end

def within(seconds)
  end_time = Time.new + Integer(seconds)
  begin
    yield
  rescue RSpec::Expectations::ExpectationNotMetError => e
    raise e if Time.new > end_time
    @browser.refresh
    retry
  end
end
# this name sucks
def repeat_within(seconds, &block)
  end_time = Time.new + Integer(seconds)
  begin
    block.call
  rescue RSpec::Expectations::ExpectationNotMetError => e
    raise e if Time.new > end_time
    # api searches are pretty quick, lets pause a second to not hit it so fast
    sleep 1
    retry
  end
end

def check_search_result(wrapper_element, element, title, in_ok)
  if title == "none"
    wrapper_element.should_not exist
  else
    element.should exist
    if in_ok
      element.text.should include title
    else
      element.text.should == title
    end
  end
end

def check_api_search_result(search_result, title, in_ok)
  if title == "none"
    search_result.length.should == 0
  else
    if in_ok
      search_result["title"].to_s.should include title
    else
      search_result["title"].to_s.should == title
    end
  end
end

def check_all_search_results(title, not_searching, in_ok)
  found = on(SearchResultsPage).results.any? do |result|
    begin
      check_search_result(result.parent, result, title, in_ok)
      true
    rescue
      false
    end
  end
  found.should == !not_searching
end

def check_all_api_search_results(title, not_searching, in_ok)
  found = @api_result["search"].any? do |result|
    begin
      check_api_search_result(result, title, in_ok)
      true
    rescue
      false
    end
  end
  found.should == !not_searching
end

def check_api_highlight(key, index, highlighted, in_ok)
  fail RSpec::Expectations::ExpectationNotMetError unless @api_result["search"][index].key? key
  text = @api_result["search"][index][key].gsub(/<span class="searchmatch">(.*?)<\/span>/, '*\1*')
  if in_ok
    text.should include(highlighted)
  else
    text.should == highlighted
  end
end
