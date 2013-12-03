# encoding: utf-8

Before("@setup_main") do
  if !$setup_main
    steps %Q{
      Given a page named Template:Template Test exists with contents pickles [[Category:TemplateTagged]]
      And a page named Catapult/adsf exists with contents catapult subpage [[Catapult]]
      And a page named Links To Catapult exists with contents [[Catapult]]
      And a page named Catapult exists with contents ♙ asdf [[Category:Weaponry]]
      And a page named Amazing Catapult exists with contents test [[Catapult]] [[Category:Weaponry]]
      And a page named Two Words exists with contents ffnonesenseword catapult {{Template_Test}} anotherword [[Category:TwoWords]]
      And a page named África exists with contents for testing
      And a page named Rdir exists with contents #REDIRECT [[Two Words]]
      And a page named AlphaBeta exists with contents [[Category:Alpha]] [[Category:Beta]]
      And a file named File:Savepage-greyed.png exists with contents Savepage-greyed.png and description Screenshot, for test purposes, associated with https://bugzilla.wikimedia.org/show_bug.cgi?id=52908 .
      And a page named IHaveAVideo exists with contents [[File:How to Edit Article in Arabic Wikipedia.ogg|thumb|267x267px]]
      And a page named IHaveASound exists with contents [[File:Serenade for Strings -mvt-1- Elgar.ogg]]
      And a page named IHaveATwoWordCategory exists with contents [[Category:CategoryWith ASpace]]
    }
    $setup_main = true
  end
end

Before("@setup_weight") do
  if !$setup_weight
    steps %Q{
      Given a page named TestWeight Smaller exists with contents TestWeight
      And a page named TestWeight Smaller/A exists with contents [[TestWeight Smaller]]
      And a page named TestWeight Smaller/B exists with contents [[TestWeight Smaller]]
      And a page named TestWeight Larger exists with contents TestWeight
      And a page named TestWeight Larger/Redirect exists with contents #REDIRECT [[TestWeight Larger]]
      And a page named TestWeight Larger/A exists with contents [[TestWeight Larger]]
      And a page named TestWeight Larger/B exists with contents [[TestWeight Larger/Redirect]]
      And a page named TestWeight Larger/C exists with contents [[TestWeight Larger/Redirect]]
    }
    $setup_weight = true
  end
end

Before("@setup_headings") do
  if !$setup_headings
    steps %Q{
      Given a page named HasHeadings exists with contents @has_headings.txt
      And a page named HasReferencesInText exists with contents References [[Category:HeadingsTest]]
    }
    $setup_headings = true
  end
end

Before("@setup_javascript_injection") do
  if !$setup_headings
    steps %Q{
      Given a page named Javascript Direct Inclusion exists with contents @javascript.txt
      Given a page named Javascript Pre Tag Inclusion exists with contents @javascript_in_pre.txt
    }
    $setup_headings = true
  end
end

Before("@setup_namespaces") do
  if !$setup_namespaces
    steps %Q{
      Given a page named Talk:Two Words exists with contents why is this page about catapults?
      And a page named Help:Smoosh exists with contents test
      And a page named File:Nothingasdf exists with contents nothingasdf
    }
    $setup_namespaces = true
  end
end

Before("@setup_suggestions") do
  if !$setup_suggestions
    steps %Q{
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
      And a page named Template:Noble Pipe exists with contents pipes are so noble
      And a page named Rrr Word 1 exists with contents #REDIRECT [[Popular Culture]]
      And a page named Rrr Word 2 exists with contents #REDIRECT [[Popular Culture]]
      And a page named Rrr Word 3 exists with contents #REDIRECT [[Noble Somethingelse3]]
      And a page named Rrr Word 4 exists with contents #REDIRECT [[Noble Somethingelse4]]
      And a page named Rrr Word 5 exists with contents #REDIRECT [[Noble Somethingelse5]]
    }
    $setup_suggestions = true
  end
end

Before("@setup_highlighting") do
  if !$setup_highlighting
    steps %Q{
      Given a page named Rashidun Caliphate exists with contents @rashidun_caliphate.txt
      And a page named Crazy Rdir exists with contents #REDIRECT [[Two Words]]
      And a page named Insane Rdir exists with contents #REDIRECT [[Two Words]]
    }
  end
  $setup_highlighting = true
end

Before("@setup_more_like_this") do
  if !$setup_more_like_this
    # The MoreLikeMe term must appear in "a bunch" of pages for it to be used in morelike: searches
    steps %Q{
      Given a page named More Like Me 1 exists with contents MoreLikeMe MoreLikeMe
      And a page named More Like Me 2 exists with contents MoreLikeMe MoreLikeMe
      And a page named More Like Me 3 exists with contents MoreLikeMe MoreLikeMe
      And a page named More Like Me 4 exists with contents MoreLikeMe MoreLikeMe
      And a page named More Like Me 5 exists with contents MoreLikeMe MoreLikeMe
    }
  end
  $setup_more_like_this = true
end

Before("@setup_phrase_rescore") do
  if !$setup_phrase_rescore
    steps %Q{
      Given a page named Rescore Test Words exists
      And a page named Test Words Rescore Rescore exists
      And a page named Rescore Test TextContent exists with contents Chaff
      And a page named Rescore Test HasTextContent exists with contents Rescore Test TextContent
    }
  end
  $setup_phrase_rescore = true
end

Before("@exact_quotes") do
  if !$exact_quotes
    steps %Q{
      Given a page named Contains A Stop Word exists
      And a page named Doesn't Actually Contain Stop Words exists
      And a page named Pick* exists
    }
  end
  $exact_quotes = true
end

Before("@programmer_friendly") do
  if !$programmer_friendly
    steps %Q{
      Given a page named $wgNamespaceAliases exists
      And a page named PFSC exists with contents snake_case
      And a page named PascalCase exists
    }
    $programmer_friendly = true
  end
end

Before("@stemmer") do
  if !$stemmer
    steps %Q{
      Given a page named StemmerTest Aliases exists
      And a page named StemmerTest Used exists
    }
  end
  $stemmer = true
end

Before("@prefix_filter") do
  if !$prefix_filter
    steps %Q{
      Given a page named Prefix Test exists
      And a page named Foo Prefix Test exists with contents [[Prefix Test]]
      And a page named Prefix Test/AAAA exists with contents [[Prefix Test]]
      And a page named Prefix Test AAAA exists with contents [[Prefix Test]]
      And a page named Talk:Prefix Test exists with contents [[Prefix Test]]
    }
  end
  $prefix_filter = true
end

Before("@prefer_recent") do
  if !$prefer_recent
    # These are updated per process instead of per test because of the 20 second wait
    # Note that the scores have to be close together because 20 seconds doesn't mean a whole lot
    steps %Q{
      Given a page named PreferRecent First exists with contents %{epoch}
      And a page named PreferRecent Second Second exists with contents %{epoch}
      And wait 20 seconds
      And a page named PreferRecent Third exists with contents %{epoch}
    }
  end
  $prefer_recent = true
end
