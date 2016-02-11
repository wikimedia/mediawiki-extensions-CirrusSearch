require "cgi"

# Page where users complete the process of moving a page.
class MovePage
  include PageObject

  page_url "Special:MovePage/<%=CGI.escape(params[:page_name].gsub(' ', '_'))%>"

  h1(:first_heading, class: "firstHeading")
  text_field(:new_title, id: "wpNewTitleMain")
  checkbox(:leave_redirect, id: "wpLeaveRedirect")
  button(:move, name: "wpMove")
end
