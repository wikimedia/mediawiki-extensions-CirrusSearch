# Page showing the article with some actions.  This is the page that everyone
# is used to reading on wikpedia.  My mom would recognize this page.
class ArticlePage
  include PageObject

  page_url URL.url("<%=params[:page_name]%>")

  h1(:title, id: "firstHeading")
  table(:file_history, class: "filehistory")
  cell(:file_last_comment) { |page| page.file_history_element[1][page.file_history_element[1].columns - 1] }
  link(:create_link, text: "Create")
  link(:create_source_link, text: "Create source")

  # Does the last file comment contain some text?
  def last_file_comment_contains(text)
    file_history? && file_last_comment? && file_last_comment.include?(text)
  end
end
