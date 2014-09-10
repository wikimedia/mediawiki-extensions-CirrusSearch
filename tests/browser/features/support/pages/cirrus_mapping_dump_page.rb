# JSON dump of Cirrus' mapping.
class CirrusMappingDumpPage
  include PageObject

  page_url URL.url("../w/api.php?format=json&action=cirrus-mapping-dump")
end
