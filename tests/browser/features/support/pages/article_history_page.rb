require "cgi"

# Page with article history.  Also allows authorized users to hide revisions.
class ArticleHistoryPage
  include PageObject

  page_url URL.url("../w/index.php?title=<%=CGI.escape(params[:page_name])%>&action=history")

  ul(:history, id: "pagehistory")
  checkbox(:second_most_recent_checkbox) { |page| page.history_element.checkbox_element(index: 1) }
  button(:change_visibility_of_selected, class: "mw-history-revisiondelete-button")
end
