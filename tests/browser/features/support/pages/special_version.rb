class SpecialVersion
  include PageObject

  page_url URL.url("/wiki/Special:Version")

  table(:software_table, id: "sv-software")

  def software_table_row(name)
    software_table_element.cell_element(:text => name)
  end
end
