Given(/^a page named (.*) exists(?: with contents (.*))?$/) do |title, text|
  if !text then
    text = title
  end
  edit_page(title, text, false)
end

Given(/^a file named (.*) exists with contents (.*) and description (.*)$/) do |title, contents, description|
  upload_file(title, contents, description)   # Make sure the file is correct
  edit_page(title, description, false)        # Make sure the description is correct
end

Given(/^a page named (.*) doesn't exist$/) do |title|
  step("I delete #{title}")
end

When(/^I delete (?!the second)(.+)$/) do |title|
  require "mediawiki_api"
  client = MediawikiApi::Client.new("#{ENV['MEDIAWIKI_URL']}../w/api.php", false)
  client.log_in(ENV['MEDIAWIKI_USER'], ENV['MEDIAWIKI_PASSWORD'])
  result = client.delete_page(title, "Testing")
  result.status.should eq 200
  if !result.body.include?("missingtitle") then
    result.body.should_not include '{"error":{"code"'
  end
end
When(/^I am at a random (.+) page$/) do |namespace|
  visit(ArticlePage, using_params: {page_name: "Special:Random/#{namespace}"})
end
When(/^I edit (.+) to add (.+)$/) do |title, text|
  edit_page(title, text, true)
end
When(/^I delete the second most recent revision of (.*)$/) do |title|
  visit(ArticleHistoryPage, using_params: {page_name: title}) do |page|
    page.check_second_most_recent_checkbox
    page.change_visibility_of_selected
  end
  on(ArticleRevisionDeletePage) do |page|
    page.check_revisions_text
    page.change_visibility_of_selected
  end
end
When(/^I go to (.*)$/) do |title|
  visit(ArticlePage, using_params: {page_name: title})
end
When(/^I move (.*) to (.*) and (do not )?leave a redirect$/) do |from, to, no_redirect|
  visit(MovePage, using_params: {page_name: from}) do |page|
    page.first_heading.should_not eq "No such target page"
    page.new_title = to
    if no_redirect then
      page.uncheck_leave_redirect
    else
      page.check_leave_redirect
    end
    page.move
  end
end

Then(/^there is a software version row for (.+)$/) do |name|
  on(SpecialVersion).software_table_row(name).exists?
end
Then(/^I am on a page titled (.*)$/) do |title|
  on(ArticlePage).title.should == title
end
Then(/^I am on a page in the (.*) namespace$/) do |namespace|
  if namespace == 'main' then
    on(ArticlePage).title.index(":").should == nil
  else
    on(ArticlePage).title.index("#{namespace}:").should_not == nil
  end
end

def edit_page(title, text, add)
  if text.start_with?("@")
    text = File.read("articles/" + text[1..-1])
  end
  require "mediawiki_api"
  client = MediawikiApi::Client.new("#{ENV['MEDIAWIKI_URL']}../w/api.php", false)
  fetched_text = client.get_wikitext(title)
  if fetched_text.status == 404 then
    fetched_text = ""
  else
    fetched_text.status.should eq 200
    fetched_text = fetched_text.body.strip
  end
  if (add)
    # Note that the space keeps from jamming words together
    text = fetched_text + " " + text
  end
  if fetched_text.strip != text.strip then
    client.log_in(ENV['MEDIAWIKI_USER'], ENV['MEDIAWIKI_PASSWORD'])
    result = client.create_page(title, text)
    result.status.should eq 200
    result.body.should_not include '{"error":{"code"'
  end
end

def upload_file(title, contents, description)
  contents = "articles/" + contents
  md5 = Digest::MD5.hexdigest(File.read(contents))
  md5_string = "md5: #{md5}"
  visit(ArticlePage, using_params: {page_name: title}) do |page|
    if page.file_history? &&
        page.file_last_comment? &&
        page.file_last_comment.include?(md5_string)
      return
    end
    if !page.upload?
      step "I am logged in"
    end
    # If this doesn't exist then file uploading is probably disabled
    page.upload
  end
  on(UploadFilePage) do |page|
    page.description = description + "\n" + md5_string
    page.file = File.absolute_path(contents)
    page.submit
    page.error_element.should_not exist
  end
end
