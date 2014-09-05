# JSON dump of Cirrus' settings.
class CirrusSettingsDumpPage
  include PageObject

  page_url URL.url("../w/api.php?format=json&action=cirrus-settings-dump")
end
