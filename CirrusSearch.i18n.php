<?php

/**
 * Messages for CirrusSearch extension
 */

$messages = array();

/**
 * English
 */
$messages['en'] = array(
	'cirrussearch-desc' => 'Elasticsearch-powered search for MediaWiki',
	'cirrussearch-backend-error' => 'We could not complete your search due to a temporary problem.  Please try again later.',
	'cirrussearch-ignored-headings' => ' #<!-- leave this line exactly as it is --> <pre>
# Headings that will be ignored by search.
# Changes to this take effect as soon as the page with the heading is indexed.
# You can force page reindexing by doing a null edit.
# Syntax is as follows:
#   * Everything from a "#" character to the end of the line is a comment
#   * Every non-blank line is the exact title to ignore, case and everything
References
External links
See also
 #</pre> <!-- leave this line exactly as it is -->',
);

/** Message documentation (Message documentation)
 * @author Shirayuki
 */
$messages['qqq'] = array(
	'cirrussearch-desc' => '{{desc|name=Cirrus Search|url=http://www.mediawiki.org/wiki/Extension:CirrusSearch}}
"Elasticsearch" and "Solr" are full-text search engines. See http://www.elasticsearch.org/',
	'cirrussearch-backend-error' => 'Error message shown to the users when we have an issue communicating with our search backend',
	'cirrussearch-ignored-headings' => 'Headings that will be ignored by search. You can translate the text, including "Leave this line exactly as it is". Some lines of this messages have one (1) leading space.',
);

/** Asturian (asturianu)
 * @author Xuacu
 */
$messages['ast'] = array(
	'cirrussearch-desc' => 'Gueta col motor Elasticsearch pa MediaWiki',
	'cirrussearch-backend-error' => 'Nun pudimos completar la gueta por un problema temporal. Por favor, vuelva a intentalo más sero.',
	'cirrussearch-ignored-headings' => " #<!-- dexar esta llinia exactamente como ta --> <pre>
# Testeres que nun se tendrán en cuenta na gueta.
# Los cambios fechos equí son efeutivos nel momentu que s'indexa la páxina cola testera.
# Pue forzar el reindexáu d'una páxina faciendo una edición nula.
# La sintaxis ye la siguiente:
#   * Tolo qu'hai dende un caráuter \"#\" al fin de llinia ye un comentariu
#   * Cada llinia non-balera ye'l títulu exactu a descartar, incluyendo mayúscules y demás
Referencies
Enllaces esternos
Ver tamién
 #</pre> <!-- dexar esta llinia exactamente como ta -->",
);

/** Bikol Central (Bikol Central)
 * @author Geopoet
 */
$messages['bcl'] = array(
	'cirrussearch-desc' => 'Elastikongpaghanap-makusugon na panhanap para sa MediaWiki',
	'cirrussearch-backend-error' => 'Dae nyamo makukumpleto an saimong paghahanap nin huli sa sarong temporaryong problema.
Tabi man paki-otroha giraray oro-atyan.',
);

/** Belarusian (Taraškievica orthography) (беларуская (тарашкевіца)‎)
 * @author Wizardist
 */
$messages['be-tarask'] = array(
	'cirrussearch-desc' => 'Пошук у MediaWiki з дапамогай ElasticSearch',
	'cirrussearch-backend-error' => 'Мы не змаглі выканаць пошукавы запыт з-за часовых праблемаў. Паспрабуйце пазьней, калі ласка.',
);

/** Breton (brezhoneg)
 * @author Fohanno
 */
$messages['br'] = array(
	'cirrussearch-backend-error' => "N'hon eus ket gallet kas hoc'h enklask da benn abalamour d'ur gudenn dibad. Esaeit en-dro diwezhatoc'h, mar plij.",
);

/** Catalan (català)
 * @author QuimGil
 */
$messages['ca'] = array(
	'cirrussearch-desc' => 'Cerca realitzada amb Elasticsearch per a MediaWiki',
	'cirrussearch-backend-error' => 'La seva cerca no ha pogut ser completada degut a un problema temporal. Si us plau, provi-ho més tard.',
);

/** German (Deutsch)
 * @author Metalhead64
 */
$messages['de'] = array(
	'cirrussearch-desc' => 'Solr-betriebene Suche',
	'cirrussearch-backend-error' => 'Deine Suche konnte aufgrund eines vorübergehenden Problems nicht abgeschlossen werden. Bitte später erneut versuchen.',
	'cirrussearch-ignored-headings' => ' #<!-- diese Zeile nicht verändern --> <pre>
# Überschriften, die von der Suche ignoriert werden.
# Diese Änderungen werden wirksam, sobald die Seite mit der Überschrift indexiert wurde.
# Du kannst die Seitenindexierung erzwingen, indem du einen Nulledit durchführst.
# Syntax:
#   * Alles, was einer Raute („#“) bis zum Zeilenende folgt, ist ein Kommentar.
#   * Jede nicht-leere Zeile ist der exakte zu ignorierende Titel.
Einzelnachweise
Weblinks
Siehe auch
 #</pre> <!-- diese Zeile nicht verändern -->',
);

/** Spanish (español)
 * @author Luis Felipe Schenone
 */
$messages['es'] = array(
	'cirrussearch-desc' => 'Hace que la búsqueda sea con Solr',
	'cirrussearch-backend-error' => 'No pudimos completar tu búsqueda debido a un problema temporario. Por favor intenta de nuevo más tarde.',
);

/** French (français)
 * @author Gomoko
 */
$messages['fr'] = array(
	'cirrussearch-desc' => 'Fait effectuer la recherche par Solr',
	'cirrussearch-backend-error' => 'Nous n’avons pas pu mener à bien votre recherche à cause d’un problème temporaire. Veuillez réessayer ultérieurement.',
);

/** Galician (galego)
 * @author Toliño
 */
$messages['gl'] = array(
	'cirrussearch-desc' => 'Procura baseada en Elasticsearch para MediaWiki',
	'cirrussearch-backend-error' => 'Non puidemos completar a súa procura debido a un problema temporal. Inténteo de novo máis tarde.',
	'cirrussearch-ignored-headings' => ' #<!-- Deixe esta liña tal e como está --> <pre>
# Cabeceiras que serán ignoradas nas buscas.
# Os cambios feitos aquí realízanse en canto se indexa a páxina coa cabeceira.
# Pode forzar o reindexado da páxina facendo unha edición baleira.
# A sintaxe é a seguinte:
#   * Todo o que vaia despois dun carácter "#" ata o final da liña é un comentario
#   * Toda liña que non estea en branco é o título exacto que ignorar, coas maiúsculas e minúsculas
Referencias
Ligazóns externas
Véxase tamén
 #</pre> <!-- Deixe esta liña tal e como está -->',
);

/** Hebrew (עברית)
 * @author Amire80
 */
$messages['he'] = array(
	'cirrussearch-desc' => 'חיפוש במדיה־ויקי באמצעות Elasticsearch',
	'cirrussearch-backend-error' => 'לא הצלחנו להשלים את החיפוש שלך בשל בעיה זמנית. נא לנסות שוב מאוחר יותר.',
);

/** Interlingua (interlingua)
 * @author McDutchie
 */
$messages['ia'] = array(
	'cirrussearch-desc' => 'Recerca pro MediaWiki actionate per Elasticsearch',
	'cirrussearch-backend-error' => 'Un problema temporari ha impedite le completion del recerca. Per favor reproba plus tarde.',
);

/** Italian (italiano)
 * @author Beta16
 */
$messages['it'] = array(
	'cirrussearch-desc' => 'Ricerca realizzata con Elasticsearch per MediaWiki',
	'cirrussearch-backend-error' => 'Non si è riuscito a completare la tua ricerca a causa di un problema temporaneo. Riprova più tardi.',
	'cirrussearch-ignored-headings' => ' #<!-- lascia questa riga esattamente come è --> <pre>
# Elenco delle intestazioni che saranno ignorate dalla ricerca.
# Le modifiche a questa pagina saranno effettive non appena la pagina sarà indicizzata.
# Puoi forzare la re-indicizzazione di una pagina effettuando una modifica nulla.
# La sintassi è la seguente:
#   * Tutto dal carattere "#" alla fine della riga è un commento
#   * Tutte le righe non vuote sono le intestazioni esatte da ignorare, maiuscolo/minuscolo e tutto
Note
Voci correlate
Collegamenti esterni
 #</pre> <!-- lascia questa riga esattamente come è -->',
);

/** Japanese (日本語)
 * @author Fryed-peach
 * @author Shirayuki
 */
$messages['ja'] = array(
	'cirrussearch-desc' => 'MediaWiki 用の Elasticsearch 検索',
	'cirrussearch-backend-error' => '一時的な問題により検索を実行できませんでした。後でやり直してください。',
);

/** Korean (한국어)
 * @author 아라
 */
$messages['ko'] = array(
	'cirrussearch-desc' => '미디어위키를 위한 Elasticsearch 검색',
	'cirrussearch-backend-error' => '일시적인 문제 떄문에 찾기를 완료할 수 없습니다. 나중에 다시 시도하세요.',
);

/** Luxembourgish (Lëtzebuergesch)
 * @author Robby
 */
$messages['lb'] = array(
	'cirrussearch-desc' => 'Elasticsearch-Sichfonctioun fir MediaWiki',
	'cirrussearch-backend-error' => 'Mir konnten Är Sich wéint engem temporäre Problem net maachen. Probéiert w.e.g. méi spéit nach eng Kéier.',
);

/** Macedonian (македонски)
 * @author Bjankuloski06
 */
$messages['mk'] = array(
	'cirrussearch-desc' => 'Пребарување со Solr',
	'cirrussearch-backend-error' => 'Не можам наполно да го изведам пребарувањето поради привремен проблем. Обидете се подоцна.',
	'cirrussearch-ignored-headings' => ' #<!-- не менувајте ништо во овој ред --> <pre>
# Заглавија што ќе се занемарат при пребарувањето.
# Измените во ова ќе стапат на сила штом ќе се индексира страницата со заглавието.
# Можете да наметнете преиндексирање на страницата ако извршите празно уредување.
# Синтаксата е следнава:
#   * Сето она што од знакот „#“ до крајот на редот е коментар
#   * Секој непразен ред е точниот наслов што треба да се занемари, разликувајќи големи од мали букви и сето останато
Наводи
Надворешни врски
Поврзано
 #</pre> <!-- не менувајте ништо во овој ред -->',
);

/** Malay (Bahasa Melayu)
 * @author Anakmalaysia
 */
$messages['ms'] = array(
	'cirrussearch-desc' => 'Enjin pencarian yang dikuasakan oleh Elasticsearch untuk MediaWiki',
	'cirrussearch-backend-error' => 'Kami tidak dapat melengkapkan pencarian anda disebabkan masalah yang sementara. Sila cuba lagi nanti.',
);

/** Dutch (Nederlands)
 * @author Bluyten
 * @author Siebrand
 */
$messages['nl'] = array(
	'cirrussearch-desc' => 'Zoeken via Solr',
	'cirrussearch-backend-error' => 'Als gevolg van een tijdelijk probleem kon uw zoekopdracht niet worden voltooit. Probeer het later opnieuw.',
);

/** Occitan (occitan)
 * @author Cedric31
 */
$messages['oc'] = array(
	'cirrussearch-desc' => 'Fa efectuar la recèrca per Solr',
	'cirrussearch-backend-error' => 'Avèm pas pogut menar corrèctament vòstra recèrca a causa d’un problèma temporari. Ensajatz tornarmai ulteriorament.',
);

/** Brazilian Portuguese (português do Brasil)
 * @author Jaideraf
 */
$messages['pt-br'] = array(
	'cirrussearch-desc' => "Mecanismo de busca ''Elasticsearch'' para MediaWiki",
	'cirrussearch-backend-error' => 'Não foi possível completar a busca devido a um problema temporário. Por favor, tente novamente mais tarde.',
);

/** tarandíne (tarandíne)
 * @author Joetaras
 */
$messages['roa-tara'] = array(
	'cirrussearch-desc' => 'Ricerche Elasticsearch-powered pe MediaUicchi',
	'cirrussearch-backend-error' => "Non ge putime combletà 'a ricerca toje pe 'nu probbleme tembonarèe. Pe piacere pruève cchiù tarde.",
);

/** Swedish (svenska)
 * @author Jopparn
 */
$messages['sv'] = array(
	'cirrussearch-backend-error' => 'Vi kunde inte slutföra din sökning på grund av ett tillfälligt problem. Försök igen senare.',
);

/** Ukrainian (українська)
 * @author Andriykopanytsia
 * @author Ата
 */
$messages['uk'] = array(
	'cirrussearch-desc' => 'Вмикає пошук з допомогою Solr',
	'cirrussearch-backend-error' => 'Нам не вдалося завершити ваш пошук через тимчасову проблему. Спробуйте ще раз пізніше.',
	'cirrussearch-ignored-headings' => ' #<!-- залиште цей рядок точно таким, яким він є --> <pre>
# Заголовки, які будуть ігноруватися при пошуці.
# Зміни, які набирають сили при індексуванні сторінки з заголовком.
# Ви можете примусити переіндексувати сторінку з нульовим редагуванням.
# Синтаксис наступний:
#   * Усе, що починається з символу "#" до кінця рядка, є коментарем
#   * Кожний непорожній рядок є точним заголовком для ігнорування
Посилання
Зовнішні посилання
Див. також
 #</pre> <!-- залиште цей рядок точно таким, яким він є -->',
);

/** Vietnamese (Tiếng Việt)
 * @author Minh Nguyen
 */
$messages['vi'] = array(
	'cirrussearch-desc' => 'Công cụ tìm kiếm Elasticsearch dành cho MediaWiki',
	'cirrussearch-backend-error' => 'Không thể hoàn tất truy vấn của bạn vì một vấn đề tạm thời. Xin vui lòng thử lại sau.',
);

/** Simplified Chinese (中文（简体）‎)
 * @author Qiyue2001
 * @author TianyinLee
 */
$messages['zh-hans'] = array(
	'cirrussearch-desc' => 'MediaWiki专用的Elasticsearch搜索',
	'cirrussearch-backend-error' => '由于出现暂时性的问题，我们未能完成你的搜寻。请稍后再试。',
);

/** Traditional Chinese (中文（繁體）‎)
 * @author Justincheng12345
 */
$messages['zh-hant'] = array(
	'cirrussearch-desc' => 'MediaWiki的Solr搜尋',
	'cirrussearch-backend-error' => '由於出現暫時性的問題，我們未能完成你的搜尋。請稍後再試。',
);
