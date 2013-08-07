class DeletePage
  include PageObject

  page_url URL.url('../w/index.php?title=<%=params[:page_name]%>&action=delete')

  button(:delete, :value => 'Delete page')
end
