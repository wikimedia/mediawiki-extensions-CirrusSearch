class ArticlePage
  include PageObject

  page_url URL.url("<%=params[:page_name]%>")

  h1(:title, id: "firstHeading")
  table(:file_history, :class => "filehistory")
  cell(:file_last_comment){ |page| page.file_history_element[1][page.file_history_element[1].columns - 1] }
  link(:upload, text: "Upload file")
  link(:create_link, text: "Create")
  link(:create_source_link, text: "Create source")
end
