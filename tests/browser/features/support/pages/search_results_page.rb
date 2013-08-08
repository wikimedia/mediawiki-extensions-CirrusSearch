class SearchResultsPage
  include PageObject

  page_url URL.url('/w/index.php?search')

  text_field(:search, id: 'searchText')
  h1(:title, id: 'firstHeading')
  div(:first_result, :class => 'mw-search-result-heading')
  button(:simple_search_button, value: 'Search')
  text_field(:search_input, name: 'search')
  div(:suggestion_wrapper, class: 'searchdidyoumean')
  def suggestion
    suggestion_wrapper_element.link_element.text
  end
  def suggestion_element
    suggestion_wrapper_element.link_element
  end
  def results
    @browser.divs(:class => 'mw-search-result-heading')
  end
end
