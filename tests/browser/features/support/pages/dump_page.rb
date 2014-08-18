require "cgi"

class CirrusDumpPage
  include PageObject

  page_url URL.url("../w/index.php?title=<%=CGI.escape(params[:page_name])%>&action=cirrusdump")
end
