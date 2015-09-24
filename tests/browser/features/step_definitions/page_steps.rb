Given(/^a page named (.*) exists(?: with contents (.*))?$/) do |title, text|
  text = title unless text
  edit_page(title, text, false)
end

Given(/^a file named (.*) exists( on commons)? with contents (.*) and description (.*)$/) do |title, on_commons, contents, description|
  current_api = on_commons ? commons_api : api
  upload_file(title, contents, description, current_api)   # Make sure the file is correct
  edit_page(title, description, false, current_api)        # Make sure the description is correct
end

Given(/^there are (\d+) pages named (.*) with contents (.*)?$/) do |count, title, text|
  count = count.to_i
  (1..count).each do |i|
    new_title = title % i
    text = new_title unless text
    edit_page(new_title, text, false)
  end
end

Given(/^there are (\d+) redirects to (.+) of the form (.+)$/) do |count, target, form|
  count = count.to_i
  text = "#REDIRECT [[#{target}]]"
  (1..count).each do |i|
    new_title = form % i
    edit_page(new_title, text, false)
  end
end

Given(/^a page named (.*) doesn't exist$/) do |title|
  step("I delete #{title}")
end

When(/^I delete (on commons)?(?!the second)(.+)$/) do |on_commons, title|
  require "mediawiki_api"
  url = "#{ENV["MEDIAWIKI_URL"]}../w/api.php" unless on_commons
  url = "#{ENV["MEDIAWIKI_COMMONS_API_URL"]}" if on_commons
  client = MediawikiApi::Client.new("#{url}", false)
  client.log_in(ENV["MEDIAWIKI_USER"], ENV["MEDIAWIKI_PASSWORD"])
  begin
    result = client.delete_page(title, "Testing")
    result.status.should eq 200
  rescue MediawikiApi::ApiError => e
    # If we get an error it better be that the page doesn't exist, which would be ok.
    expect(e.info).to include "doesn't exist"
  end
end
When(/^I am at a random (.+) page$/) do |namespace|
  visit(ArticlePage, using_params: { page_name: "Special:Random/#{namespace}" })
end
When(/^I edit (.+) to add (.+)$/) do |title, text|
  edit_page(title, text, true)
end
When(/^I delete the second most recent revision of (.*)$/) do |title|
  visit(ArticleHistoryPage, using_params: { page_name: title }) do |page|
    page.check_second_most_recent_checkbox
    page.change_visibility_of_selected
  end
  on(ArticleRevisionDeletePage) do |page|
    page.check_revisions_text
    page.change_visibility_of_selected
  end
end
When(/^I delete the second most recent revision via api of (.*)$/) do |title|
  # find the revision id
  result = api.prop(
    :revisions,
    titles: title,
    rvlimit: 2
  )
  second_rev_id = result["query"].values[0].values[0]["revisions"][1]["revid"]
  # delete its text
  api.action(
    :revisiondelete,
    type: "revision",
    ids: [second_rev_id],
    hide: "content",
    token_type: "edit"
  )
end
When(/^I go to (.*)$/) do |title|
  visit(ArticlePage, using_params: { page_name: title })
end
When(/^I move (.*) to (.*) and (do not )?leave a redirect$/) do |from, to, no_redirect|
  visit(MovePage, using_params: { page_name: from }) do |page|
    page.first_heading.should_not eq "No such target page"
    page.new_title = to
    if no_redirect
      page.uncheck_leave_redirect
    else
      page.check_leave_redirect
    end
    page.move
  end
end
When(/^I move (.*) to (.*) and (do not )?leave a redirect via api$/) do |from, to, no_redirect|
  api.action(
    :move,
    from: from,
    to: to,
    noredirect: no_redirect ? 1 : 0
  )
end
Then(/^there is a software version row for (.+)$/) do |name|
  on(SpecialVersion).software_table_row(name).exists?
end
Then(/^I am on a page titled (.*)$/) do |title|
  on(ArticlePage).title.should == title
end
Then(/^I am on a page in the (.*) namespace$/) do |namespace|
  if namespace == "main"
    on(ArticlePage).title.index(":").should.nil?
  else
    on(ArticlePage).title.index("#{namespace}:").should_not.nil?
  end
end
