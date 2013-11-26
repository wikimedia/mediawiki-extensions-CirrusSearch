class SearchResultsPage
  include PageObject

  page_url URL.url("/w/index.php?search=<%=params[:search]%>&prefix=<%=params[:prefix]%>")

  text_field(:search, id: "searchText")
  h1(:title, id: "firstHeading")
  unordered_list(:search_results, :class => "mw-search-results")
  li(:first_result_wrapper){ |page| page.search_results_element.list_item_element(:index => 0) }
  link(:first_result){ |page| page.first_result_wrapper_element.div_element(:class => "mw-search-result-heading").link_element }
  div(:first_result_text){ |page| page.first_result_wrapper_element.div_element(:class => "searchresult") }
  span(:first_result_alttitle_wrapper){ |page| page.first_result_wrapper_element.span_element(:class => "searchalttitle") }
  link(:first_result_alttitle) { |page| page.first_result_alttitle_wrapper_element.link_element }
  link(:first_image_result){ table_element(:class => "searchResultImage").cell_element(:index => 1).link_element(:index => 0) }
  li(:second_result_wrapper){ |page| page.search_results_element.list_item_element(:index => 1) }
  link(:second_result){ |page| page.second_result_wrapper_element.div_element(:class => "mw-search-result-heading").link_element }
  button(:simple_search_button, value: "Search")
  text_field(:search_input, name: "search")
  div(:suggestion_wrapper, class: "searchdidyoumean")
  div(:error_report, class: "error")
  def suggestion_element
    suggestion_wrapper_element.link_element
  end
  def highlighted_suggestion
    get_highlighted_text(suggestion_element)
  end
  def results
    @browser.divs(:class => "mw-search-result-heading")
  end
  def result_data
    @browser.divs(:class => "mw-search-result-data")
  end
  def first_result_highlighted_title
    get_highlighted_text(first_result_element)
  end
  def first_result_highlighted_text
    get_highlighted_text(first_result_text_element)
  end
  def first_result_highlighted_alttitle
    get_highlighted_text(first_result_alttitle_element)
  end
  private

  def get_highlighted_text(element)
    result = element.element.attribute_value("innerHTML").strip
    result.gsub("<span class=\"searchmatch\">", "*").
           gsub("</span>", "*").
           gsub("<em>", "*").
           gsub("</em>", "*")
  end
end
