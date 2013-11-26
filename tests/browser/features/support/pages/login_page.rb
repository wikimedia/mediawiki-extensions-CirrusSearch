class LoginPage
  include PageObject

  page_url URL.url("Special:UserLogin")

  button(:login, id: "wpLoginAttempt")
  text_field(:password, id: "wpPassword1")
  text_field(:username, id: "wpName1")

  def login_with(username, password)
    self.username = username
    self.password = password
    login
  end
end
