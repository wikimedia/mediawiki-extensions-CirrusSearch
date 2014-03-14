class SearchResultsPage
  include PageObject

  page_url URL.url("/w/index.php?search=<%=params[:search]%><%if (params[:prefix]) %>&prefix=<%=params[:prefix]%><% end %>")

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
  li(:third_result_wrapper){ |page| page.search_results_element.list_item_element(:index => 2) }
  link(:third_result){ |page| page.third_result_wrapper_element.div_element(:class => "mw-search-result-heading").link_element }
  li(:fourth_result_wrapper){ |page| page.search_results_element.list_item_element(:index => 3) }
  link(:fourth_result){ |page| page.fourth_result_wrapper_element.div_element(:class => "mw-search-result-heading").link_element }
  li(:fifth_result_wrapper){ |page| page.search_results_element.list_item_element(:index => 4) }
  link(:fifth_result){ |page| page.fifth_result_wrapper_element.div_element(:class => "mw-search-result-heading").link_element }
  li(:sixth_result_wrapper){ |page| page.search_results_element.list_item_element(:index => 5) }
  link(:sixth_result){ |page| page.sixth_result_wrapper_element.div_element(:class => "mw-search-result-heading").link_element }
  li(:seventh_result_wrapper){ |page| page.search_results_element.list_item_element(:index => 6) }
  link(:seventh_result){ |page| page.seventh_result_wrapper_element.div_element(:class => "mw-search-result-heading").link_element }
  button(:simple_search_button, value: "Search")
  text_field(:search_input, name: "search")
  div(:suggestion_wrapper, class: "searchdidyoumean")
  div(:error_report, class: "error")
  paragraph(:create_page, :class => "mw-search-createlink")

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
    if first_result_alttitle_element.exists? then
      get_highlighted_text(first_result_alttitle_element)
    else
      get_highlighted_text(first_result_alttitle_wrapper_element)
    end
  end
  # Note that this is really only useful if Warnings are being echod to the page.  In testing environments they usually are.
  def warning
    text = @browser.text
    if text.start_with?("Warning: ") then
      return text.slice("Warning: ".length, text.index("\n"))
    else
      return ""
    end
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
