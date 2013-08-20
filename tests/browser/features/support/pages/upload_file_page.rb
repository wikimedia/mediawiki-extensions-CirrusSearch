class UploadFilePage
  include PageObject

  text_area(:description, id: 'wpUploadDescription')
  file_field(:file, id: 'wpUploadFile')
  button(:submit, value: 'Upload file')
  div(:error, class: 'error')
end
