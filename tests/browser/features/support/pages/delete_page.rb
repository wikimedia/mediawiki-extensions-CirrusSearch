require 'cgi'

class DeletePage
  include PageObject

  page_url URL.url('../w/index.php?title=<%=CGI.escape(params[:page_name])%>&action=delete')

  button(:delete, :value => 'Delete page')
end
