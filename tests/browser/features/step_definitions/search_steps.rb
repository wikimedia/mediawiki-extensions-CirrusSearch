require "cgi"

Given(/^I am at the search results page(?: with the search (.+?)(?: and the prefix (.+))?)?$/) do |search, prefix|
  visit(SearchResultsPage, using_params: { search: search, prefix: prefix })
end
When(/^I go search for (.*)$/) do |search|
  visit(SearchResultsPage, using_params: { search: URI.encode(search) })
end
Before do
  @search_vars = {
    "%ideographic_whitespace%" => "\u3000".force_encoding("utf-8")
  }
end
When(/^I locate the page id of (.*) and store it as (%.*%)$/) do |title, varname|
  @search_vars[varname] = page_id_of title
end
When(/^I reset did you mean suggester options$/) do
  @didyoumean_options = {}
end
When(/^I set did you mean suggester option (.*) to (.*)$/) do |varname, value|
  @didyoumean_options ||= {}
  @didyoumean_options[varname] = value
end

When(/^I api search( with rewrites enabled)?( with disabled incoming link weighting)?(?: with offset (\d+))?(?: in the (.*) language)?(?: in namespaces? (\d+(?: \d+)*))? for (.*)$/) do |enable_rewrites, incoming_links, offset, lang, namespaces, search|
  begin
    options = {
      sroffset: offset,
      srnamespace: (namespaces || "0").split(/ /),
      uselang: lang,
      cirrusBoostLinks: incoming_links ? "no" : "yes",
      enablerewrites: enable_rewrites ? 1 : 0
    }
    options = options.merge(@didyoumean_options) if defined?@didyoumean_options

    @api_result = search_for(
      search.gsub(/%[^ {]+%/, @search_vars)
        .gsub(/%\{\\u([\dA-Fa-f]{4,6})\}%/) do  # replace %{\uXXXX}% with the unicode code point
          [Regexp.last_match[1].hex].pack("U")
        end,
      options
    )
  rescue MediawikiApi::ApiError => e
    @api_error = e
  rescue MediawikiApi::HttpError => e
    @api_error = e
  end
end
When(/^I get api suggestions for (.*)$/) do |search|
  begin
    @api_result = suggestions_for(search)
  rescue MediawikiApi::ApiError => e
    @api_error = e
  rescue MediawikiApi::HttpError => e
    @api_error = e
  end
end
Then(/^the api should offer to search for pages containing (.*)$/) do |term|
  @api_result[0].should == term
end
When(/^I ask suggestion API for (.*)$/) do |search|
  begin
    @api_result = suggestions_for_api(search)
  rescue MediawikiApi::ApiError => e
    @api_error = e
  end
end
When(/^I ask suggestion API at most (\d+) items? for (.*)$/) do |limit, search|
  begin
    @api_result = suggestions_for_api(search, limit)
  rescue MediawikiApi::ApiError => e
    @api_error = e
  end
end
Then(/^the API should produce list containing (.*)/) do |term|
  found = false
  @api_result[1].each do |el|
    found = true if el == term
  end
  found.should == true
end
Then(/^the API should produce list starting with (.*)/) do |term|
  @api_result[1][0].should == term
end
Then(/^the API should produce list of length (\d+)/) do |length|
  @api_result[1].length.should == length.to_i
end
Then(/^the API should produce empty list/) do
  @api_result[1].should == []
end
When(/^I get api near matches for (.*)$/) do |search|
  begin
    @api_result = search_for(
      search,
      srwhat: "nearmatch"
    )
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
  browser.goto("#{browser.url}&uselang=#{language}")
end
When(/^I disable incoming links in the weighting$/) do
  browser.goto("#{browser.url}&cirrusBoostLinks=no")
end
When(/^I jump to offset (.+)$/) do |offset|
  browser.goto("#{browser.url}&offset=#{offset}")
end
When(/^I click the (.*) link$/) do |text|
  browser.link(text: text).click
end
When(/^I click the (.*) label(?:s)?$/) do |text|
  text.split(",").each do |link_text|
    link_text.strip!
    if link_text.include? " or "
      found = false
      link_text.split(" or ").each do |or_text|
        or_text.strip!
        label = browser.label(text: or_text)
        if label.exists?
          found = true
          label.click
        end
      end
      fail "none of \"#{link_text}\" could be found" unless found
    else
      browser.label(text: link_text).click
    end
  end
end
When(/^I dump the cirrus data for (.+)$/) do |title|
  visit(CirrusDumpPage, using_params: { page_name: title })
end
When(/^I request a dump of the query$/) do
  browser.goto("#{browser.url}&cirrusDumpQuery=yes")
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
When(/^I set the custom param ([^ ]+) to ([^ ]+)/) do |param, value|
  browser.goto("#{browser.url}&#{param}=#{value}")
end

When(/^I set More Like This Options to ([^ ]+) field, word length to (\d+) and I search for (.+)$/) do |field, length, search|
  step("I search for " + search)
  browser.goto("#{browser.url}&cirrusMtlUseFields=yes&cirrusMltFields=#{field}&cirrusMltMinTermFreq=1&cirrusMltMinDocFreq=1&cirrusMltMinWordLength=#{length}")
end

When(/^I set More Like This Options to ([^ ]+) field, percent terms to match to ([\.\d]+) and I search for (.+)$/) do |field, percent, search|
  step("I search for " + search)
  browser.goto("#{browser.url}&cirrusMtlUseFields=yes&cirrusMltFields=#{field}&cirrusMltMinTermFreq=1&cirrusMltMinDocFreq=1&cirrusMltMinWordLength=0&cirrusMltPercentTermsToMatch=#{percent}")
end

When(/^I set More Like This Options to bad settings and I search for (.+)$/) do |search|
  step("I search for " + search)
  browser.goto("#{browser.url}&cirrusMtlUseFields=yes&cirrusMltFields=title&cirrusMltMinTermFreq=100&cirrusMltMinDocFreq=200000&cirrusMltMinWordLength=190&cirrusMltPercentTermsToMatch=1")
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
Then(/^the api warns (.*)$/) do |warning|
  @api_error.should_not be nil
  @api_error.info.should == warning
end
Then(/^the api returns error code (.*)$/) do |code|
  @api_error.should_not be nil
  @api_error.status.should == code.to_i
end
Then(/^(.+) is the (.+) api suggestion$/) do |title, position|
  pos = %w(first second third fourth fifth sixth seventh eighth ninth tenth).index position
  if title == "none"
    if @api_error && pos == 1
      true
    else
      @api_result[1].length.should be <= pos
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
  found = on(SearchPage).all_results_elements.map(&:text)
  if should_not
    expect(found).to_not include(title)
  else
    expect(found).to include(title)
  end
end
Then(/^(.+) is( not)? in the api suggestions$/) do |title, should_not|
  if should_not
    expect(@api_result[1]).to_not include(title)
  else
    expect(@api_result[1]).to include(title)
  end
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
Then(/^there is no ((?:[^ ])+(?: or (?:[^ ])+)*) search result$/) do |indexes|
  on(SearchResultsPage) do |page|
    indexes.split(/ or /).each do |index|
      expect(page.send("#{index}_result_wrapper_element")).to_not exist
    end
  end
end
Then(/^(.+) is( in)? the ((?:[^ ])+(?: or (?:[^ ])+)*) search result$/) do |title, in_ok, indexes|
  if title == "none"
    step "there is no #{indexes} search result"
  else
    on(SearchResultsPage) do |page|
      results = indexes.split(/ or /).map do |index|
        page.send("#{index}_result_element").text
      end
      if in_ok
        expect(results).to include(include(title))
      else
        expect(results).to include(title)
      end
    end
  end
end
Then(/^there is no ((?:[^ ])+(?: or (?:[^ ])+)*) api search result$/) do |indexes|
  @api_error.should be nil
  positions = indexes.split(/ or /).map do |index|
    %w(first second third fourth fifth sixth seventh eighth ninth tenth).index index
  end
  expect(@api_result["search"].length).to be <= positions.min.to_i
end
Then(/^(.+) is( in)? the ((?:[^ ])+(?: or (?:[^ ])+)*) api search result$/) do |title, in_ok, indexes|
  if title == "none"
    step "there is no #{indexes} api search result"
  else
    found = indexes.split(/ or /).map do |index|
      pos = %w(first second third fourth fifth sixth seventh eighth ninth tenth).index index
      if @api_result["search"][pos].nil?
        nil
      else
        @api_result["search"][pos]["title"]
      end
    end
    if in_ok
      expect(found).to include(include(title))
    else
      expect(found).to include(title)
    end
  end
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
Then(/^within (\d+) seconds api searching for (.*) yields (.*?) as the first result(?: and (.*?) as the second result)?$/) do |seconds, term, title, title2|
  repeat_within(seconds) do
    step("I api search for " + term)
    step("#{title} is the first api search result")
    step("#{title2} is the second api search result") if title2
  end
end
Then(/^within (\d+) seconds api searching for (.*) yields no results$/) do |seconds, term|
  repeat_within(seconds) do
    step("I api search for " + term)
    step("there are no api search results")
  end
end
Then(/^within (\d+) seconds api searching (.*) yields (.*) as (the highlighted .* of the first api search result)$/) do |seconds, search, highlight, highlight_suffix|
  repeat_within(seconds) do
    step("I api search " + search)
    step(highlight + " is " + highlight_suffix)
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
  fixed = @api_result["searchinfo"]["suggestionsnippet"]
  fixed = fixed.gsub(%r{<em>(.*?)</em>}, '*\1*') unless fixed.nil?
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
Then(/there are no errors reported by the api$/) do
  @api_error.should be nil
end
Then(/this error is reported by api: (.+)$/) do |expected_error|
  @api_error.code.should be == "srsearch-error"
  CGI.unescapeHTML(@api_error.info).should == expected_error.strip
end

Then(/^the title still exists$/) do
  on(ArticlePage).title_element.should exist
end
Then(/^there are( no)? search results with (.+) in the data/) do |should_not_find, within|
  found = on(SearchResultsPage).result_data.map(&:text)
  if should_not_find
    expect(found).to_not include(include(within))
  else
    expect(found).to include(include(within))
  end
end
Then(/^there are( no)? api search results with (.+) in the data/) do |should_not_find, within|
  found = @api_result["search"].map do |result|
    result["snippet"]
  end
  if should_not_find
    expect(found).to_not include(within)
  else
    expect(found).to include(within)
  end
end
Then(/^there is no warning$/) do
  on(SearchResultsPage).warning.should == ""
end

When(/^I globally freeze indexing$/) do
  api.action(
    :'cirrus-freeze-writes',
    token_type: false,
    formatversion: 2
  )
end
When(/^I globally thaw indexing$/) do
  api.action(
    :'cirrus-freeze-writes',
    token_type: false,
    formatversion: 2,
    thaw: 1
  )
end
When(/^I reindex suggestions$/) do
  api.action(
    :'cirrus-suggest-index',
    token_type: false
  )
end

def within(seconds)
  end_time = Time.new + Integer(seconds)
  begin
    yield
  rescue RSpec::Expectations::ExpectationNotMetError => e
    raise e if Time.new > end_time
    browser.refresh
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

def check_all_search_results(title, not_searching, in_ok)
  found = on(SearchResultsPage).results.map(&:text)
  check_all_search_results_internal(found, title, not_searching, in_ok)
end

def check_all_api_search_results(title, not_searching, in_ok)
  found = @api_result["search"].map do |result|
    result["title"]
  end
  check_all_search_results_internal(found, title, not_searching, in_ok)
end

def check_all_search_results_internal(found, title, not_searching, in_ok)
  if in_ok
    match = include(include(title))
  else
    match = include(title)
  end

  if not_searching
    expect(found).to_not match
  else
    expect(found).to match
  end
end

def check_api_highlight(key, index, highlighted, in_ok)
  expect(@api_result["search"].length).to be > index
  expect(@api_result["search"][index]).to have_key(key)
  text = @api_result["search"][index][key].gsub(%r{<span class="searchmatch">(.*?)</span>}, '*\1*')
  if in_ok
    expect(text).to include(highlighted)
  else
    expect(text).to be == highlighted
  end
end
