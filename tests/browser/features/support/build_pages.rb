# encoding: utf-8

Before('@setup_main') do
  if !$setup_main
    steps %Q{
      Given a page named Template:Template Test exists with contents pickles [[Category:TemplateTagged]]
      And a page named Catapult/adsf exists with contents catapult subpage [[Catapult]]
      And a page named Links To Catapult exists with contents [[Catapult]]
      And a page named Catapult exists with contents ♙ asdf [[Category:Weaponry]]
      And a page named Amazing Catapult exists with contents test [[Catapult]] [[Category:Weaponry]]
      And a page named Two Words exists with contents ffnonesenseword catapult {{Template_Test}} [[Category:TwoWords]]
      And a page named África exists with contents for testing
      And a page named Rdir exists with contents #REDIRECT [[Two Words]]
      And a page named AlphaBeta exists with contents [[Category:Alpha]] [[Category:Beta]]
    }
    $setup_main = true
  end
end

Before('@setup_namespaces') do
  if !$setup_namespaces
    steps %Q{
      Given a page named Talk:Two Words exists with contents why is this page about catapults?
      And a page named Help:Smoosh exists with contents test
      And a page named File:Nothingasdf exists with contents nothingasdf
    }
    $setup_namespaces = true
  end
end

Before('@setup_suggestions') do
  if !$setup_namespaces
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
    }
    $setup_namespaces = true
  end
end

Before('@setup_highlighting') do
  if !$setup_highlighting
    steps %Q{
      Given a page named Rashidun Caliphate exists with contents @rashidun_caliphate.txt
    }
  end
  $setup_highlighting = true
end
