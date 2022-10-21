<?php

return [
	'default' => [
		// List the type of fields (only keyword or text are supported, defaults to text)
		'field_types' => [],
		// List the max size of the fields (such fields are truncated even-though the doc size is
		// not larger than max_size.
		'max_field_size' => [ /* "field_name" => 123 */ ],
		// max_size (strings) a document should have
		// truncation pattern are only applied if the document_size is greater than max_size
		'max_size' => PHP_INT_MAX,
		// Truncate these fields (key) if their size is greater that the value.
		'fields' => [ /* "field_name" => 123 */ ],
	],
	// Profile to limit file_text & opening_text to "sane" values
	'wmf' => [
		'field_types' => [],
		'max_field_size' => [
			'file_text' => 51200,
			'opening_text' => 10000,
		],
		'max_size' => PHP_INT_MAX,
	],
	// Profile that attempts to keep the doc size under 4Mb
	'wmf_capped' => [
		'field_types' => [
			'external_links' => 'keyword',
			'outgoing_links' => 'keyword',
			'template' => 'keyword',
			'category' => 'keyword',
		],
		'max_field_size' => [
			'file_text' => 51200,
			'opening_text' => 10000,
		],
		// target max size of a document
		'max_size' => 4000000,
		// Truncate these fields if their size is greater that this.
		// based on the 99.999 percentile of all the WMF wikis as of october 2022.
		// opening_text & file_text are omitted here since they're forced to 10k and 51k.
		// Truncation happens in the order these fields are declared and stops when the expected
		// size is reached. Here external_links is touched first and source_text which is considered
		// the most important field is touched as a last resort.
		'fields' => [
			'external_links' => 203873,
			'outgoing_links' => 228190,
			'auxiliary_text' => 241007,
			'heading' => 11895,
			'template' => 49408,
			'text' => 693279,
			'source_text' => 998752,
		],
		// searchable via hastemplate::CirrusSearchOversizeDocument
		'markup_template' => 'CirrusSearchOversizeDocument'
	]
];
