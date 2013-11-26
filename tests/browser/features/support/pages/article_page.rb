class ArticlePage
  include PageObject

  page_url URL.url("<%=params[:page_name]%>")

  h1(:title, id: "firstHeading")
  table(:file_history, :class => "filehistory")
  cell(:file_last_comment){ table_element(:class => "filehistory")[1][5] }
  link(:upload, text: "upload it")
  link(:upload_new_version, text: "Upload a new version of this file")
  link(:create_link, text: "Create")
  link(:create_source_link, text: "Create source")
end
