class RandomPage
  include PageObject

  page_url URL.url("Special:Random")

  # Unfortunately some wikis don't have a button at searchButton and some put it
  # at mw-searchButton so we make both and use whichever one we can.  Yet worse,
  # the searchButton actually works like hitting enter in the search box, going
  # directly to the top prefix suggestion which we don't always want.
  button(:search_button, id: "searchButton")
  button(:simple_search_button, id: "mw-searchButton")
  text_field(:search_input, id: "searchInput")
end
