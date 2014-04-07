class SearchPage
  include PageObject

  button(:search_button, id: "searchButton")
  text_field(:search_input, id: "searchInput")
  div(:search_results, class: "suggestions-results")
  div(:search_special, class: "suggestions-special")
  div(:one_result, class: "suggestions-result")
  links(:all_results, class: "suggestions-result"){ |page| page.search_results_element.link_elements }
end
