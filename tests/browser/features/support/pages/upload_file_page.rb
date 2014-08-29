# Page where users can upload a file.
class UploadFilePage
  include PageObject

  text_area(:description, id: "wpUploadDescription")
  file_field(:file, id: "wpUploadFile")
  button(:submit, value: "Upload file")
  div(:error, class: "error")

  def upload(contents, description, md5)
    self.description = description + "\n" + md5
    self.file = File.absolute_path(contents)
    submit
  end
end
