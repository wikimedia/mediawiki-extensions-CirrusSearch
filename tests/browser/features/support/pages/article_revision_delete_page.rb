# Page where users complete the process of hiding a revision.
class ArticleRevisionDeletePage
  include PageObject

  checkbox(:revisions_text, id: "wpHidePrimary")
  button(:change_visibility_of_selected, name: "wpSubmit")
end
