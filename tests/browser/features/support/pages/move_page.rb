require "cgi"

class MovePage
  include PageObject

  page_url URL.url("Special:MovePage/<%=CGI.escape(params[:page_name].gsub(' ', '_'))%>")

  h1(:first_heading, id: "firstHeading")
  text_field(:new_title, id: "wpNewTitleMain")
  checkbox(:leave_redirect, id: "wpLeaveRedirect")
  button(:move, name: "wpMove")
end
