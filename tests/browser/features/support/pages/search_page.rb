# Page with all the search options.
class SearchPage
  include PageObject

  button(:search_button, id: "searchButton")
  text_field(:search_input, id: "searchInput")
  div(:search_results, class: "suggestions-results")
  div(:search_special, class: "suggestions-special")
  div(:first_result, class: "suggestions-result", index: 0)
  div(:second_result, class: "suggestions-result", index: 1)
  links(:all_results, class: "suggestions-result") { |page| page.search_results_element.link_elements }
end
