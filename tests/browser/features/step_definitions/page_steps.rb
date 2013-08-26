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
  visit(ArticlePage, using_params: {page_name: title}) do |page|
    page.create_link_element.should exist
  end
end

When(/^I delete (.+)$/) do |title|
  visit(DeletePage, using_params: {page_name: title}) do |page|
    page.delete
  end
end
When(/^I edit (.+) to add (.+)$/) do |title, text|
  edit_page(title, text, true)
end

def edit_page(title, text, add)
  if text.start_with?('@')
    text = File.read('features/support/articles/' + text[1..-1])
  end
  visit(EditPage, using_params: {page_name: title}) do |page|
    if (!page.article_text? and page.login?) then
      # Looks like we're not being given the article text probably because we're
      # trying to edit an article that requires us to be logged in.  Lets try
      # logging in.
      step 'I am logged in'
      visit(EditPage, using_params: {page_name: title})
    end
    if (page.article_text.strip != text.strip) then
      if (!page.save? and page.login?) then
        # Looks like I'm at a page I don't have permission to change and I'm not
        # logged in.  Lets log in and try again.
        step 'I am logged in'
        visit(EditPage, using_params: {page_name: title})
      end
      if !add then
        page.article_text = ''
      end
      # Firefox chokes on huge batches of text so split it into chunks and use
      # send_keys rather than page-objects built in += because that clears and
      # resends everything....
      text.chars.each_slice(1000) do |chunk|
        page.article_text_element.send_keys(chunk)
      end
      page.save
    end
  end
end

def upload_file(title, contents, description)
  contents = 'features/support/articles/' + contents
  md5 = Digest::MD5.hexdigest(File.read(contents))
  md5_string = "md5: #{md5}"
  visit(ArticlePage, using_params: {page_name: title}) do |page|
    if page.file_history? && page.file_last_comment? && page.file_last_comment.include?(md5_string)
      return
    end
    if !(page.upload_new_version? || page.upload?)
      step 'I am logged in'
      visit(ArticlePage, using_params: {page_name: title})
    end
    if page.upload?
      # New file, upload it
      page.upload
    else
      # Existing file, update it
      page.upload_new_version
    end
  end
  on(UploadFilePage) do |page|
    page.description = description + "\n" + md5_string
    page.file = File.absolute_path(contents)
    page.submit
    page.error_element.should_not exist
  end
end
