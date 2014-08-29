Given(/^a page named (.*) exists(?: with contents (.*))?$/) do |title, text|
  text = title unless text
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
  client = MediawikiApi::Client.new("#{ENV["MEDIAWIKI_URL"]}../w/api.php", false)
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

def edit_page(title, text, add)
  text = File.read("articles/" + text[1..-1]) if text.start_with?("@")
  require "mediawiki_api"
  fetched_text = get_page_text(title)
  # Note that the space keeps words from smashing together
  text = fetched_text + " " + text if add
  return if fetched_text.strip == text.strip
  client = MediawikiApi::Client.new("#{ENV["MEDIAWIKI_URL"]}../w/api.php", false)
  client.log_in(ENV["MEDIAWIKI_USER"], ENV["MEDIAWIKI_PASSWORD"])
  result = client.create_page(title, text)
  expect(result.status).to eq 200
  expect(result.warnings?).to eq false
end

# Gets page text using the api.
def get_page_text(title)
  require "mediawiki_api"
  client = MediawikiApi::Client.new("#{ENV["MEDIAWIKI_URL"]}../w/api.php", false)
  fetched_text = client.get_wikitext(title)
  return "" if fetched_text.status == 404
  fetched_text.status.should eq 200
  fetched_text.body.strip
end

# Uploads a file if the file's MD5 doesn't match what is already uploaded.
def upload_file(title, contents, description)
  contents = "articles/" + contents
  md5 = "md5: #{Digest::MD5.hexdigest(File.read(contents))}"
  visit(ArticlePage, using_params: { page_name: title }) do |page|
    page.last_file_comment_contains(md5)
    step "I am logged in" unless page.upload?
    page.upload
  end
  on(UploadFilePage).upload(contents, description, md5)
  on(UploadFilePage).error_element.should_not exist
end
