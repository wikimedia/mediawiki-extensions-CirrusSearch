# JSON dump of Cirrus' settings.
class CirrusSettingsDumpPage
  include PageObject

  page_url "../w/api.php?format=json&formatversion=2&action=cirrus-settings-dump"
end
