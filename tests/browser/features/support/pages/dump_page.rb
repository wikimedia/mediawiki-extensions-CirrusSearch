require "cgi"

# Dump of data that Cirrus has in its index for the article.
class CirrusDumpPage
  include PageObject

  page_url "../w/index.php?title=<%=CGI.escape(params[:page_name])%>&action=cirrusdump"
end
