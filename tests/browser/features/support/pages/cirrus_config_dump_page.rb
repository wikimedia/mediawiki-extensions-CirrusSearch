class CirrusConfigDumpPage
  include PageObject

  page_url URL.url("../w/api.php?format=json&action=cirrus-config-dump")
end
