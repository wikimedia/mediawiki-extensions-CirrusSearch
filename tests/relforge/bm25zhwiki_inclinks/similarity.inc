<?php

$wgCirrusSearchSimilarityProfiles['relforge'] = array(
	'similarity' => array(
		'title' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
		'title_plain' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
		'opening_text' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
		'opening_text_plain' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
		'auxiliary_text' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
		'auxiliary_text_plain' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
		'category' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.3,
		),
		'category_plain' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.3,
		),
		'redirect' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.3,
		),
		'redirect_plain' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.3,
		),
		'heading' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.3,
		),
		'heading_plain' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.3,
		),
		'suggest' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.3,
		),
		'text' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
		'text_plain' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
		'all' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
		'all_plain' => array(
			'type' => 'BM25',
			'k1' => 1.2,
			'b' => 0.75,
		),
	),
	'fields' => array(
		'__default__' => 'text',
		// Field specific config
		'opening_text' => 'opening_text',
		'opening_text.plain' => 'opening_text_plain',
		'category' => 'category',
		'category.plain' => 'category_plain',
		'title' => 'title',
		'title.plain' => 'title_plain',
		'redirect' => 'redirect',
		'redirect.plain' => 'redirect_plain',
		'text' => 'text',
		'text.plain' => 'text_plain',
		'all' => 'all',
		'all.plain' => 'all_plain',
		'heading' => 'heading',
		'heading.plain' => 'heading_plain',
		'suggest' => 'suggest',
	),
);
