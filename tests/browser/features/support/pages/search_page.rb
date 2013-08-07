class SearchPage
  include PageObject

  div(:one_result, class: 'suggestions-result')
  button(:search_button, id: 'searchButton')
  text_field(:search_input, id: 'searchInput')
  div(:search_results, class: 'suggestions-results')
  div(:search_special, class: 'suggestions-special')
end
