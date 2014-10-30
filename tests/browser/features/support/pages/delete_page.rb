require "cgi"

# Page where users complete the process of deleting a page.
class DeletePage
  include PageObject

  page_url URL.url("../w/index.php?title=<%=CGI.escape(params[:page_name])%>&action=delete")

  button(:delete, value: "Delete page")
end
