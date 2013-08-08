class ArticlePage
  include PageObject

  page_url URL.url('<%=params[:page_name]%>')

  h1(:title, id: 'firstHeading')
end
