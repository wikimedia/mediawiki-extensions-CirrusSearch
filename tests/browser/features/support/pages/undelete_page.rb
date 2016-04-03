# Page with all the search options.
class SpecialUndeletePage
  include PageObject

  page_url "Special:Undelete?fuzzy=1"

  button(:search_button, id: "searchUndelete")
  text_field(:search_input, id: "prefix")
  ul(:search_results, id: "undeleteResultsList")
  li(:first_result, class: "undeleteResult", index: 0)
  li(:second_result, class: "undeleteResult", index: 1)
  links(:all_results, class: "undeleteResult") { |page| page.search_results_element.link_elements }
end
