<?php

/**
 * Builds schema.xml and all the required files for it to function correctly.
 */
class SchemaBuilder extends ConfigBuilder {
	public function __construct($where) {
		parent::__construct($where);
	}

	public function build() {
		$typesBuilder = new TypesBuilder( $this->where );
		$types = $this->indent( $typesBuilder->build() );
		$wikiId = wfWikiId();
		$content = <<<XML
<?xml version="1.0" encoding="UTF-8" ?>
<schema name="$wikiId" version="1.5">
	<uniqueKey>id</uniqueKey>
	<fields>
		<field name="_version_" type="long" indexed="true" stored="true" required="true" /> <!-- Required for Solr Cloud -->
		<field name="id" type="id" indexed="true" stored="true" required="true" />
		<field name="namespace" type="integer" indexed="true" stored="false" required="true" />
		<field name="title" type="text_splitting" indexed="true" stored="true" required="true" />
		<field name="text" type="text_splitting" indexed="true" stored="true" />
		<field name="textLen" type="long" indexed="true" stored="false" />
		<field name="timestamp" type="triedate" indexed="true" stored="false" />
		<field name="category" type="text_splitting" indexed="true" stored="false" multiValued="true" />

		<!-- Power prefix searches -->
		<field name="titlePrefix" type="prefix" indexed="true" stored="false" />
		<!-- Power spell check -->
		<field name="allText" type="spell" indexed="true" stored="false" multiValued="true" />
	</fields>
	<copyField source="title" dest="titlePrefix" />
	<copyField source="title" dest="allText" />
	<copyField source="text" dest="allText" />
	$types
</schema>
XML;
		$this->writeConfigFile( 'schema.xml', $content );
	}
}
