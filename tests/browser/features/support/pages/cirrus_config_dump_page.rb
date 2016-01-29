# JSON dump of Cirrus' config.
class CirrusConfigDumpPage
  include PageObject

  page_url "../w/api.php?format=json&formatversion=2&action=cirrus-config-dump"
end
