Given(/^a page named (.*) exists with contents (.*)$/) do |title, text|
  edit_page(title, text, false)
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