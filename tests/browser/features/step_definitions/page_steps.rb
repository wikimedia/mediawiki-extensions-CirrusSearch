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
      if (add) then
        page.article_text += text
      else
        page.article_text = text
      end
      page.save
    end
  end
end