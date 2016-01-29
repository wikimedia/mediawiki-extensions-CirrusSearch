require "cgi"

# Page where you can edit the article.
class EditPage
  include PageObject

  page_url "../w/index.php?title=<%=CGI.escape(params[:page_name])%>&action=edit"

  text_area(:article_text, id: "wpTextbox1")
  button(:save, id: "wpSave")
  a(:login, text: "Log in")
end
