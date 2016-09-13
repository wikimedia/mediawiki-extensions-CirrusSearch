# encoding: utf-8

World(CirrusSearchApiHelper)

After("@frozen") do
  step("I globally thaw indexing")
end

main = false
setup_main = lambda do |world|
  unless main
    world.steps %(
      Given a page named Template:Template Test exists with contents pickles [[Category:TemplateTagged]]
      And a page named Catapult/adsf exists with contents catapult subpage [[Catapult]]
      And a page named Links To Catapult exists with contents [[Catapult]]
      And a page named Catapult exists with contents ♙ asdf [[Category:Weaponry]]
      And a page named Amazing Catapult exists with contents test [[Catapult]] [[Category:Weaponry]]
      And a page named Category:Weaponry exists with contents Weaponry refers to any items designed or used to attack and kill or destroy other people and property.
      And a page named Two Words exists with contents ffnonesenseword catapult {{Template_Test}} anotherword [[Category:TwoWords]] [[Category:Categorywith Twowords]] [[Category:Categorywith " Quote]]
      And a page named AlphaBeta exists with contents [[Category:Alpha]] [[Category:Beta]]
      And a page named IHaveATwoWordCategory exists with contents [[Category:CategoryWith ASpace]]
      And a page named Functional programming exists with contents Functional programming is referential transparency.
      And a page named वाङ्मय exists
      And a page named वाङ्‍मय exists
      And a page named वाङ‍्मय exists
      And a page named वाङ्‌मय exists
      And a page named ChangeMe exists with contents foo
        )
    main = true
  end
end

clean = false
setup_clean = lambda do |world|
  unless clean
    world.steps %(
      Given I delete DeleteMeRedirect
    )
    clean = true
  end
end

redirect_loop = false
setup_redirect_loop = lambda do |world|
  unless redirect_loop
    world.steps %(
      Given a page named Redirect Loop exists with contents #REDIRECT [[Redirect Loop 1]]
      And a page named Redirect Loop 1 exists with contents #REDIRECT [[Redirect Loop 2]]
      And a page named Redirect Loop 2 exists with contents #REDIRECT [[Redirect Loop 1]]
        )
    redirect_loop = true
  end
end

main2 = false
setup_main2 = lambda do |world|
  unless main2
    world.steps %(
      Given a page named Rdir exists with contents #REDIRECT [[Two Words]]
      And a file named File:Savepage-greyed.png exists with contents Savepage-greyed.png and description Screenshot, for test purposes, associated with https://bugzilla.wikimedia.org/show_bug.cgi?id=52908 .
      And a page named IHaveAVideo exists with contents [[File:How to Edit Article in Arabic Wikipedia.ogg|thumb|267x267px]]
      And a page named IHaveASound exists with contents [[File:Serenade for Strings -mvt-1- Elgar.ogg]]
        )
    main2 = true
  end
end

commons = false
setup_commons = lambda do |world|
  unless commons
    world.steps %(

      Given I delete on commons File:OnCommons.svg
      And I delete on commons File:DuplicatedLocally.svg
      And I delete File:DuplicatedLocally.svg
      And I wait 5 seconds
      And a file named File:OnCommons.svg exists on commons with contents OnCommons.svg and description File stored on commons for test purposes
      And a file named File:DuplicatedLocally.svg exists on commons with contents DuplicatedLocally.svg and description File stored on commons and duplicated locally
      And I wait 5 seconds
      And a file named File:DuplicatedLocally.svg exists with contents DuplicatedLocally.svg and description Locally stored file duplicated on commons
      And I wait 5 seconds
        )
    commons = true
  end
end

africa = false
setup_africa = lambda do |world|
  unless africa
    world.steps %(
      Given a page named África exists with contents for testing
        )
    africa = true
  end
end

prefix = false
setup_prefix = lambda do |world|
  unless prefix
    world.steps %(
      Given a page named L'Oréal exists
      And a page named Jean-Yves Le Drian exists
        )
    prefix = true
  end
end

headings = false
setup_headings = lambda do |world|
  unless headings
    world.steps %(
      Given a page named HasHeadings exists with contents @has_headings.txt
      And a page named HasReferencesInText exists with contents References [[Category:HeadingsTest]]
      And a page named HasHeadingsWithHtmlComment exists with contents @has_headings_with_html_comment.txt
      And a page named HasHeadingsWithReference exists with contents @has_headings_with_reference.txt
        )
    headings = true
  end
end

javascript_injection = false
setup_javascript_injection = lambda do |world|
  unless javascript_injection
    world.steps %(
      Given a page named Javascript Direct Inclusion exists with contents @javascript.txt
      Given a page named Javascript Pre Tag Inclusion exists with contents @javascript_in_pre.txt
        )
    javascript_injection = true
  end
end

setup_namespaces = false
setup_setup_namespaces = lambda do |world|
  unless setup_namespaces
    world.steps %(
      Given a page named Talk:Two Words exists with contents why is this page about catapults?
      And a page named Help:Smoosh exists with contents test
      And a page named File:Nothingasdf exists with contents nothingasdf
        )
    setup_namespaces = true
  end
end

suggestions = false
setup_suggestions = lambda do |world|
  unless suggestions
    world.steps %(
      Given a page named Popular Culture exists with contents popular culture
      And a page named Nobel Prize exists with contents nobel prize
      And a page named Noble Gasses exists with contents noble gasses
      And a page named Noble Somethingelse exists with contents noble somethingelse
      And a page named Noble Somethingelse2 exists with contents noble somethingelse
      And a page named Noble Somethingelse3 exists with contents noble somethingelse
      And a page named Noble Somethingelse4 exists with contents noble somethingelse
      And a page named Noble Somethingelse5 exists with contents noble somethingelse
      And a page named Noble Somethingelse6 exists with contents noble somethingelse
      And a page named Noble Somethingelse7 exists with contents noble somethingelse
      And a page named Template:Noble Pipe 1 exists with contents pipes are so noble
      And a page named Template:Noble Pipe 2 exists with contents pipes are so noble
      And a page named Template:Noble Pipe 3 exists with contents pipes are so noble
      And a page named Template:Noble Pipe 4 exists with contents pipes are so noble
      And a page named Template:Noble Pipe 5 exists with contents pipes are so noble
      And a page named Rrr Word 1 exists with contents #REDIRECT [[Popular Culture]]
      And a page named Rrr Word 2 exists with contents #REDIRECT [[Popular Culture]]
      And a page named Rrr Word 3 exists with contents #REDIRECT [[Noble Somethingelse3]]
      And a page named Rrr Word 4 exists with contents #REDIRECT [[Noble Somethingelse4]]
      And a page named Rrr Word 5 exists with contents #REDIRECT [[Noble Somethingelse5]]
      And a page named Nobel Gassez exists with contents #REDIRECT [[Noble Gasses]]
      And there are 30 pages named Grammy Awards ed. %s with contents grammy awards
      And there are 14 pages named Grammo Awards ed. %s with contents bogus grammy awards page
      And a page named my suggest1 suggest2 exists with contents list of grammy awards winners
      And a page named my suggest2 suggest3 exists with contents list of grammy awards winners
      And a page named my suggest3 suggest4 exists with contents list of grammy awards winners
      And a page named my suggest4 suggest5 exists with contents list of grammy awards winners
      And a page named my suggest5 suggest6 exists with contents list of grammy awards winners
      And a page named my suggest6 suggest1 exists with contents list of grammy awards winners
      And a page named suggest1 suggest2 suggest3 exists with contents list of grammy awards winners
      And a page named suggest2 suggest3 suggest4 exists with contents list of grammy awards winners
      And a page named suggest3 suggest4 suggest5 exists with contents list of grammy awards winners
        )
    suggestions = true
  end
end

suggestions_stemming = false
setup_suggestions_stemming = lambda do |world|
  unless suggestions_stemming
    world.steps %(
      Given a page named Stemming Multiwords exists
      And a page named Stemming Possessive’s exists
      And a page named Stemmingsinglewords exists
      And a page named Stemmingsinglewords Other 1 exists
      And a page named Stemmingsinglewords Other 2 exists
      And a page named Stemmingsinglewords Other 3 exists
      And a page named Stemmingsinglewords Other 4 exists
      And a page named Stemmingsinglewords Other 5 exists
      And a page named Stemmingsinglewords Other 6 exists
      And a page named Stemmingsinglewords Other 7 exists
      And a page named Stemmingsinglewords Other 8 exists
      And a page named Stemmingsinglewords Other 9 exists
      And a page named Stemmingsinglewords Other 10 exists
      And a page named Stemmingsinglewords Other 11 exists
      And a page named Stemmingsinglewords Other 12 exists
        )
    suggestions_stemming = true
  end
end

highlighting = false
setup_highlighting = lambda do |world|
  unless highlighting
    world.steps %(
      Given a page named Rashidun Caliphate exists with contents @rashidun_caliphate.txt
      And a page named Crazy Rdir exists with contents #REDIRECT [[Two Words]]
      And a page named Insane Rdir exists with contents #REDIRECT [[Two Words]]
      And a page named The Once and Future King exists
      And a page named User_talk:Test exists
      And a page named Rose Trellis Faberge Egg exists with contents @rose_trellis_faberge_egg.txt
        )
  end
  highlighting = true
end

highlighting_references = false
setup_highlighting_references = lambda do |world|
  unless highlighting_references
    world.steps %(
      Given a page named References Highlight Test exists with contents @references_highlight_test.txt
        )
  end
  highlighting_references = true
end

more_like_this = false
setup_more_like_this = lambda do |world|
  unless more_like_this
    # The MoreLikeMe term must appear in "a bunch" of pages for it to be used in morelike: searches
    world.steps %(
      Given a page named More Like Me 1 exists with contents morelikesetone morelikesetone
      And a page named More Like Me 2 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me 3 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me 4 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me 5 exists with contents morelikesetone morelikesetone morelikesetone morelikesetone
      And a page named More Like Me Rdir exists with contents #REDIRECT [[More Like Me 1]]
      And a page named More Like Me Set 2 Page 1 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 2 Page 2 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 2 Page 3 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 2 Page 4 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 2 Page 5 exists with contents morelikesettwo morelikesettwo morelikesettwo
      And a page named More Like Me Set 3 Page 1 exists with contents morelikesetthree morelikesetthree
      And a page named More Like Me Set 3 Page 2 exists with contents morelikesetthree morelikesetthree
      And a page named More Like Me Set 3 Page 3 exists with contents morelikesetthree morelikesetthree
      And a page named More Like Me Set 3 Page 4 exists with contents morelikesetthree morelikesetthree
      And a page named More Like Me Set 3 Page 5 exists with contents morelikesetthree morelikesetthree
        )
  end
  more_like_this = true
end

phrase_rescore = false
setup_phrase_rescore = lambda do |world|
  unless phrase_rescore
    world.steps %(
      Given a page named Rescore Test Words Chaff exists with contents Words Test Rescore Chaff
      And a page named Test Words Rescore Rescore Test Words exists
      And a page named Rescore Test TextContent exists with contents Chaff
      And a page named Rescore Test HasTextContent exists with contents Rescore Test TextContent
        )
  end
  phrase_rescore = true
end

exact_quotes = false
setup_exact_quotes = lambda do |world|
  unless exact_quotes
    world.steps %(
      Given a page named Contains A Stop Word exists
      And a page named Doesn't Actually Contain Stop Words exists
      And a page named Pick* exists
        )
  end
  exact_quotes = true
end

programmer_friendly = false
setup_programmer_friendly = lambda do |world|
  unless programmer_friendly
    world.steps %(
      Given a page named $wgNamespaceAliases exists
      And a page named PFSC exists with contents snake_case
      And a page named PascalCase exists
      And a page named NumericCase7 exists
      And a page named this.getInitial exists
      And a page named RefToolbarBase.js exists
      And a page named PFTest Paren exists with contents this.isCamelCased()
        )
    programmer_friendly = true
  end
end

stemmer = false
setup_stemmer = lambda do |world|
  unless stemmer
    world.steps %(
      Given a page named StemmerTest Aliases exists
      And a page named StemmerTest Alias exists
      And a page named StemmerTest Used exists
      And a page named StemmerTest Guidelines exists
        )
  end
  stemmer = true
end

prefix_filter = false
setup_prefix_filter = lambda do |world|
  unless prefix_filter
    world.steps %(
      Given a page named Prefix Test exists
      And a page named Prefix Test Redirect exists with contents #REDIRECT [[Prefix Test]]
      And a page named Foo Prefix Test exists with contents [[Prefix Test]]
      And a page named Prefix Test/AAAA exists with contents [[Prefix Test]]
      And a page named Prefix Test AAAA exists with contents [[Prefix Test]]
      And a page named Talk:Prefix Test exists with contents [[Prefix Test]]
      And a page named User_talk:Prefix Test exists with contents [[Prefix Text]]
        )
  end
  prefix_filter = true
end

prefer_recent = false
setup_prefer_recent = lambda do |world|
  unless prefer_recent
    # These are updated per process instead of per test because of the 20 second wait
    # Note that the scores have to be close together because 20 seconds doesn't mean a whole lot
    world.steps %(
      Given a page named PreferRecent First exists with contents %{epoch}
      And a page named PreferRecent Second Second exists with contents %{epoch}
      And wait 20 seconds
      And a page named PreferRecent Third exists with contents %{epoch}
      And wait 10 seconds
        )
  end
  prefer_recent = true
end

hastemplate = false
setup_hastemplate = lambda do |world|
  unless hastemplate
    world.steps %(
      Given a page named MainNamespaceTemplate exists
      And a page named HasMainNSTemplate exists with contents {{:MainNamespaceTemplate}}
      And a page named Talk:TalkTemplate exists
      And a page named HasTTemplate exists with contents {{Talk:TalkTemplate}}
        )
  end
  hastemplate = true
end

boost_template = false
setup_boost_template = lambda do |world|
  unless boost_template
    world.steps %(
      Given a page named Template:BoostTemplateHigh exists with contents BoostTemplateTest
      And a page named Template:BoostTemplateLow exists with contents BoostTemplateTest
      And a page named NoTemplates BoostTemplateTest exists with contents nothing important
      And a page named HighTemplate exists with contents {{BoostTemplateHigh}}
      And a page named LowTemplate exists with contents {{BoostTemplateLow}}
        )
  end
  boost_template = true
end

go = false
setup_go = lambda do |world|
  unless go
    world.steps %(
      Given a page named MixedCapsAndLowerCase exists
        )
  end
  go = true
end

go_options = false
setup_go_options = lambda do |world|
  unless go_options
    world.steps %(
      Given a page named son Nearmatchflattentest exists
      And a page named Son Nearmatchflattentest exists
      And a page named SON Nearmatchflattentest exists
      And a page named soñ Nearmatchflattentest exists
      And a page named Son Nolower Nearmatchflattentest exists
      And a page named SON Nolower Nearmatchflattentest exists
      And a page named Soñ Nolower Nearmatchflattentest exists
      And a page named Son Titlecase Nearmatchflattentest exists
      And a page named Soñ Titlecase Nearmatchflattentest exists
      And a page named Soñ Onlyaccent Nearmatchflattentest exists
      And a page named Soñ Twoaccents Nearmatchflattentest exists
      And a page named Són Twoaccents Nearmatchflattentest exists
      And a page named son Double Nearmatchflattentest exists
      And a page named SON Double Nearmatchflattentest exists
      And a page named Bach Nearmatchflattentest exists with contents #REDIRECT [[Johann Sebastian Bach Nearmatchflattentest]]
      And a page named Bạch Nearmatchflattentest exists with contents Notice the dot under the a.
      And a page named Johann Sebastian Bach Nearmatchflattentest exists
      And a page named KOAN Nearmatchflattentest exists
      And a page named Kōan Nearmatchflattentest exists
      And a page named Koan Nearmatchflattentest exists with contents #REDIRECT [[Kōan Nearmatchflattentest]]
      And a page named Soñ Redirect Nearmatchflattentest exists
      And a page named Són Redirect Nearmatchflattentest exists
      And a page named Son Redirect Nearmatchflattentest exists with contents #REDIRECT [[Soñ Redirect Nearmatchflattentest]]
      And a page named Són Redirectnotbetter Nearmatchflattentest exists
      And a page named Soñ Redirectnotbetter Nearmatchflattentest exists with contents #REDIRECT [[Són Redirectnotbetter Nearmatchflattentest]]
      And a page named Són Redirecttoomany Nearmatchflattentest exists
      And a page named Soñ Redirecttoomany Nearmatchflattentest exists with contents #REDIRECT [[Són Redirecttoomany Nearmatchflattentest]]
      And a page named Søn Redirecttoomany Nearmatchflattentest exists
      And a page named Blah Redirectnoncompete Nearmatchflattentest exists
      And a page named Soñ Redirectnoncompete Nearmatchflattentest exists with contents #REDIRECT [[Blah Redirectnoncompete Nearmatchflattentest]]
      And a page named Søn Redirectnoncompete Nearmatchflattentest exists with contents #REDIRECT [[Blah Redirectnoncompete Nearmatchflattentest]]
        )
  end
  go_options = true
end

redirect = false
setup_redirect = lambda do |world|
  unless redirect
    world.steps %(
      Given a page named SEO Redirecttest exists with contents #REDIRECT [[Search Engine Optimization Redirecttest]]
      And a page named Redirecttest Yikes exists with contents #REDIRECT [[Redirecttest Yay]]
      And a page named User_talk:SEO Redirecttest exists with contents #REDIRECT [[User_talk:Search Engine Optimization Redirecttest]]
      And wait 3 seconds
      And a page named Seo Redirecttest exists
      And a page named Search Engine Optimization Redirecttest exists
      And a page named Redirecttest Yay exists
      And a page named User_talk:Search Engine Optimization Redirecttest exists
      And a page named PrefixRedirectRanking 1 exists
      And a page named LinksToPrefixRedirectRanking 1 exists with contents [[PrefixRedirectRanking 1]]
      And a page named TargetOfPrefixRedirectRanking 2 exists
      And a page named PrefixRedirectRanking 2 exists with contents #REDIRECT [[TargetOfPrefixRedirectRanking 2]]
        )
  end
  redirect = true
end

file_text = false
setup_file_text = lambda do |world|
  unless file_text
    world.steps %(
      Given a file named File:Linux_Distribution_Timeline_text_version.pdf exists with contents Linux_Distribution_Timeline_text_version.pdf and description Linux distribution timeline.
        )
  end
  file_text = true
end

match_stopwords = false
setup_stopwords = lambda do |world|
  unless match_stopwords
    world.steps %(
      Given a page named To exists
        )
  end
  match_stopwords = true
end

many_redirects = false
setup_many_redirects = lambda do |world|
  unless many_redirects
    world.steps %(
      Given a page named Manyredirectstarget exists with contents [[Category:ManyRedirectsTest]]
      And a page named Fewredirectstarget exists with contents [[Category:ManyRedirectsTest]]
      And a page named Many Redirects Test 1 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Many Redirects Test 2 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 1 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 2 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 3 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 4 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Useless redirect to target 5 exists with contents #REDIRECT [[Manyredirectstarget]]
      And a page named Many Redirects Test ToFew exists with contents #REDIRECT [[Fewredirectstarget]]
        )
  end
  many_redirects = true
end

relevancy = false
setup_relevancy = lambda do |world|
  unless relevancy
    world.steps %(
      Given a page named Relevancytest exists with contents it is not relevant
      And a page named Relevancytestviaredirect exists with contents not relevant
      And a page named Relevancytest Redirect exists with contents #REDIRECT [[Relevancytestviaredirect]]
      And a page named Relevancytestviacategory exists with contents Some opening text. [[Category:Relevancytest]]
      And a page named Relevancytestviaheading exists with contents ==Relevancytest==
      And a page named Relevancytestviaopening exists with contents @Relevancytestviaopening.txt
      And a page named Relevancytestviatext exists with contents [[Relevancytest]]
      And a page named Relevancytestviaauxtext exists with contents @Relevancytestviaauxtext.txt
      And a page named Relevancytestphrase phrase exists with contents not relevant
      And a page named Relevancytestphraseviaredirect exists with contents not relevant
      And a page named Relevancytestphrase Phrase Redirect exists with contents #REDIRECT [[Relevancytestphraseviaredirect]]
      And a page named Relevancytestphraseviacategory exists with contents not relevant [[Category:Relevancytestphrase phrase category]]
      And a page named Relevancytestphraseviaheading exists with contents ==Relevancytestphrase phrase heading==
      And a page named Relevancytestphraseviaopening exists with contents @Relevancytestphraseviaopening.txt
      And a page named Relevancytestphraseviatext exists with contents [[Relevancytestphrase phrase]] text
      And a page named Relevancytestphraseviaauxtext exists with contents @Relevancytestphraseviaauxtext.txt
      And a page named Relevancytwo Wordtest exists with contents relevance is bliss
      And a page named Wordtest Relevancytwo exists with contents relevance is cool
      And a page named Relevancynamespacetest exists
      And a page named Talk:Relevancynamespacetest exists
      And a page named File:Relevancynamespacetest exists
      And a page named Help:Relevancynamespacetest exists
      And a page named File talk:Relevancynamespacetest exists
      And a page named User talk:Relevancynamespacetest exists
      And a page named Template:Relevancynamespacetest exists
      And a page named Relevancylanguagetest/ja exists
      And a page named Relevancylanguagetest/en exists
      And a page named Relevancylanguagetest/ar exists
      And a page named Relevancyclosetest Foô exists
      And a page named Relevancyclosetest Foo exists
      And a page named Foo Relevancyclosetest exists
      And a page named William Shakespeare exists
      And a page named William Shakespeare Works exists with contents To be or not to be is a famous quote from Hamlet
        )
  end
  relevancy = true
end

fallback_finder = false
setup_fallback_finder = lambda do |world|
  unless fallback_finder
    world.steps %{
      Given a page named $US exists
      And a page named US exists
      And a page named Uslink exists with contents [[US]]
      And a page named Cent (currency) exists
      And a page named ¢ exists with contents #REDIRECT [[Cent (currency)]]
    }
  end
  fallback_finder = true
end

js_and_css = false
setup_js_and_css = lambda do |world|
  unless js_and_css
    world.steps %(
      Given a page named User:Tools/Some.js exists with contents @some.js
      And a page named User:Tools/Some.css exists with contents @some.css
        )
  end
  js_and_css = true
end

special_random = false
setup_special_random = lambda do |world|
  unless special_random
    world.steps %(
      Given a page named User:Random Test exists
      And a page named User_talk:Random Test exists
        )
  end
  special_random = true
end

regex = false
setup_regex = lambda do |world|
  unless regex
    world.steps %(
      Given a page named RegexEscapedForwardSlash exists with contents a/b
      And a page named RegexEscapedBackslash exists with contents a\\b
      And a page named RegexEscapedDot exists with contents a.b
      And a page named RegexSpaces exists with contents a b c
      And a page named RegexComplexResult exists with contents aaabacccccccccccccccdcccccccccccccccccccccccccccccdcccc
        )
  end
  regex = true
end

linksto = false
setup_linksto = lambda do |world|
  unless linksto
    world.steps %(
      Given a page named LinksToTest Target exists
      And a page named LinksToTest Plain exists with contents [[LinksToTest Target]]
      And a page named LinksToTest OtherText exists with contents [[LinksToTest Target]] and more text
      And a page named LinksToTest No Link exists with contents LinksToTest Target
      And a page named Template:LinksToTest Template exists with contents [[LinksToTest Target]]
      And a page named LinksToTest Using Template exists with contents {{LinksToTest Template}}
      And a page named LinksToTest LinksToTemplate exists with contents [[Template:LinksToTest Template]]
        )
    linksto = true
  end
end

filenames = false
setup_filenames = lambda do |world|
  unless filenames
    world.steps %(
      Given a file named File:No_SVG.svg exists with contents No_SVG.svg and description [[Category:Red circle with left slash]]
      And a file named File:Somethingelse_svg_SVG.svg exists with contents Somethingelse_svg_SVG.svg and description [[Category:Red circle with left slash]]
        )
    filenames = true
  end
end

removed_text = false
setup_removed_text = lambda do |world|
  unless removed_text
    world.steps %(
      Given a page named Autocollapse Example exists with contents <div class="autocollapse">inside autocollapse</div>
        )
    removed_text = true
  end
end

accent_squashing = false
setup_accent_squashing = lambda do |world|
  unless accent_squashing
    world.steps %(
      Given a page named Áccent Sorting exists
        And a page named Accent Sorting exists
        )
    accent_squashing = true
  end
end

accented_namespace = false
setup_accented_namespace = lambda do |world|
  unless removed_text
    world.steps %(
      Given a page named Mó:Test exists with contents some text
        )
    accented_namespace = true
  end
end

suggest = false
setup_suggest = lambda do |world|
  unless suggest
    world.steps %(
      Given a page named X-Men exists with contents The X-Men are a fictional team of superheroes
        And a page named Xavier, Charles exists with contents Professor Charles Francis Xavier (also known as Professor X) is the founder of [[X-Men]]
        And a page named X-Force exists with contents X-Force is a fictional team of of [[X-Men]]
        And a page named Magneto exists with contents Magneto is a fictional character appearing in American comic books
        And a page named Max Eisenhardt exists with contents #REDIRECT [[Magneto]]
        And a page named Eisenhardt, Max exists with contents #REDIRECT [[Magneto]]
        And a page named Magnetu exists with contents #REDIRECT [[Magneto]]
        And a page named Ice exists with contents It's cold.
        And a page named Iceman exists with contents Iceman (Robert "Bobby" Drake) is a fictional superhero appearing in American comic books published by Marvel Comics and is...
        And a page named Ice Man (Marvel Comics) exists with contents #REDIRECT [[Iceman]]
        And a page named Ice-Man (comics books) exists with contents #REDIRECT [[Iceman]]
        And a page named Ultimate Iceman exists with contents #REDIRECT [[Iceman]]
        And a page named Électricité exists with contents This is electicity in french.
        And a page named Elektra exists with contents Elektra is a fictional character appearing in American comic books published by Marvel Comics.
        And a page named Help:Navigation exists with contents When viewing any page on MediaWiki...
        And a page named V:N exists with contents #REDIRECT [[Help:Navigation]]
        And a page named Z:Navigation exists with contents #REDIRECT [[Help:Navigation]]
        And a page named Venom exists with contents Venom, or the Venom Symbiote, is a fictional supervillain appearing in American comic books published by Marvel Comics
        And a page named Sam Wilson exists with contents Warren Kenneth Worthington III, originally known as Angel and later as Archangel, ... Marvel Comics like [[Venom]]. {{DEFAULTSORTKEY:Wilson, Sam}}
        And a page named Zam Wilson exists with contents #REDIRECT [[Sam Wilson]]
        And a page named The Doors exists with contents The Doors were an American rock band formed in 1965 in Los Angeles.
        And a page named Hyperion Cantos/Endymion exists with contents Endymion is the third science fiction novel by Dan Simmons.
        And I reindex suggestions
    )
    suggest = true
  end
end

# Optimization for parallel runners so only one does the setup, Makes the bold
# assumption parallel runner is going to run everything.
lock_file_path = "/tmp/parallel_cucumber.lock"
AfterConfiguration do
  next unless Object.const_defined?("ParallelTests")

  if ParallelTests.first_process?
    fh = File.open(lock_file_path, File::CREAT)
    fh.flock(File::LOCK_EX)

    setup_main.call(self)
    setup_clean.call(self)
    setup_redirect_loop.call(self)
    setup_main2.call(self)
    setup_commons.call(self)
    setup_africa.call(self)
    setup_prefix.call(self)
    setup_headings.call(self)
    setup_javascript_injection.call(self)
    setup_setup_namespaces.call(self)
    setup_suggestions.call(self)
    setup_suggestions_stemming.call(self)
    setup_highlighting.call(self)
    setup_highlighting_references.call(self)
    setup_more_like_this.call(self)
    setup_phrase_rescore.call(self)
    setup_exact_quotes.call(self)
    setup_programmer_friendly.call(self)
    setup_stemmer.call(self)
    setup_prefix_filter.call(self)
    setup_prefer_recent.call(self)
    setup_hastemplate.call(self)
    setup_boost_template.call(self)
    setup_go.call(self)
    setup_go_options.call(self)
    setup_redirect.call(self)
    setup_file_text.call(self)
    setup_stopwords.call(self)
    setup_many_redirects.call(self)
    setup_relevancy.call(self)
    setup_fallback_finder.call(self)
    setup_js_and_css.call(self)
    setup_special_random.call(self)
    setup_regex.call(self)
    setup_linksto.call(self)
    setup_filenames.call(self)
    setup_removed_text.call(self)
    setup_accent_squashing.call(self)
    setup_accented_namespace.call(self)
    setup_suggest.call(self)

  else
    # Horrible hack...but whatever. Try and guarantee first
    # process has the lock already
    sleep(1) until File.exists?(lock_file_path)
    fh = File.open(lock_file_path, File::CREAT)
    # Wait for lock to be released
    fh.flock(File::LOCK_SH)

    main = true
    clean = true
    redirect_loop = true
    main2 = true
    commons = true
    africa = true
    prefix = true
    headings = true
    javascript_injection = true
    setup_namespaces = true
    suggestions = true
    suggestions_stemming = true
    highlighting = true
    highlighting_references = true
    more_like_this = true
    phrase_rescore = true
    exact_quotes = true
    programmer_friendly = true
    stemmer = true
    prefix_filter = true
    prefer_recent = true
    hastemplate = true
    boost_template = true
    go = true
    go_options = true
    redirect = true
    file_text = true
    match_stopwords = true
    many_redirects = true
    relevancy = true
    fallback_finder = true
    js_and_css = true
    special_random = true
    regex = true
    linksto = true
    filenames = true
    removed_text = true
    accent_squashing = true
    accented_namespace = true
    suggest = true

  end
  fh.flock(File::LOCK_UN)
end

at_exit do
  next unless Object.const_defined?("ParallelTests")
  next unless ParallelTests.first_process?
  ParallelTests.wait_for_other_processes_to_finish
  begin
    File.delete(lock_file_path)
  # rubocop:disable HandleExceptions
  rescue
    # -
  end
end

Before("@setup_main, @filters, @prefix, @bad_syntax, @wildcard, @exact_quotes, @phrase_prefix") do
  setup_main.call(self)
end
Before("@clean") do
  setup_clean.call(self)
end
Before("@redirect_loop") do
  setup_redirect_loop.call(self)
end
Before("@setup_main, @prefix, @bad_syntax") do
  setup_main2.call(self)
end
Before("@setup_main, @commons") do
  setup_commons.call(self)
end
Before("@setup_main, @prefix, @go, @bad_syntax") do
  setup_africa.call(self)
end
Before("@prefix") do
  setup_prefix.call(self)
end
Before("@headings") do
  setup_headings.call(self)
end
Before("@javascript_injection") do
  setup_javascript_injection.call(self)
end
Before("@setup_namespaces") do
  setup_setup_namespaces.call(self)
end
Before("@suggestions") do
  setup_suggestions.call(self)
end
Before("@suggestions", "@stemming") do
  setup_suggestions_stemming.call(self)
end
Before("@highlighting") do
  setup_highlighting.call(self)
end
Before("@highlighting", "@references") do
  setup_highlighting_references.call(self)
end
Before("@more_like_this") do
  setup_more_like_this.call(self)
end
Before("@setup_phrase_rescore") do
  setup_phrase_rescore.call(self)
end
Before("@exact_quotes") do
  setup_exact_quotes.call(self)
end
Before("@programmer_friendly") do
  setup_programmer_friendly.call(self)
end
Before("@stemmer") do
  setup_stemmer.call(self)
end
Before("@prefix_filter") do
  setup_prefix_filter.call(self)
end
Before("@prefer_recent") do
  setup_prefer_recent.call(self)
end
Before("@hastemplate") do
  setup_hastemplate.call(self)
end
Before("@boost_template") do
  setup_boost_template.call(self)
end
Before("@go") do
  setup_go.call(self)
end
Before("@go", "@options") do
  setup_go_options.call(self)
end
Before("@redirect") do
  setup_redirect.call(self)
end
Before("@file_text") do
  setup_file_text.call(self)
end
Before("@match_stopwords") do
  setup_stopwords.call(self)
end
Before("@many_redirects") do
  setup_many_redirects.call(self)
end
Before("@relevancy") do
  setup_relevancy.call(self)
end
Before("@fallback_finder") do
  setup_fallback_finder.call(self)
end
Before("@js_and_css") do
  setup_js_and_css.call(self)
end
Before("@special_random") do
  setup_special_random.call(self)
end
Before("@regex") do
  setup_regex.call(self)
end
Before("@linksto") do
  setup_linksto.call(self)
end
Before("@filenames") do
  setup_filenames.call(self)
end
Before("@removed_text") do
  setup_removed_text.call(self)
end
Before("@accent_squashing") do
  setup_accent_squashing.call(self)
end
Before("@accented_namespace") do
  setup_accented_namespace.call(self)
end
Before("@suggest") do
  setup_suggest.call(self)
end
