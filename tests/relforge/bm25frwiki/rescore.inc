<?php

// w sum with pageviews and inc links
$wgCirrusSearchPageViewsW = 0.5;//2.5;
$wgCirrusSearchPageViewsK = 8E-6;
$wgCirrusSearchPageViewsA = 0.8;

$wgCirrusSearchIncLinksW = 5;//0.7;
$wgCirrusSearchIncLinksK = 30;
$wgCirrusSearchIncLinksA = 0.7;

$wgCirrusSearchSizeW = 0.5;
$wgCirrusSearchSizeK = 300;
$wgCirrusSearchSizeA = 1;

// w sum with inc links alone (fallback with non content ns)
$wgCirrusSearchIncLinksAloneW = 7;
$wgCirrusSearchIncLinksAloneK = 30;
$wgCirrusSearchIncLinksAloneA = 0.7;

$wgCirrusSearchRescoreProfiles['relforge'] = array(
	'supported_namespaces' => 'all',
	'fallback_profile' => 'relforge_inclinks',
	'rescore' => array(
		array(
			'window' => 8192,
			'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
			'query_weight' => 1.0,
			'rescore_query_weight' => 1.0,
			'score_mode' => 'total',
			'type' => 'function_score',
			'function_chain' => 'wsum_pageviews_and_inclinks'
		),
		array(
			'window' => 8192,
			'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
			'query_weight' => 1.0,
			'rescore_query_weight' => 1.0,
			'score_mode' => 'multiply',
			'type' => 'function_score',
			'function_chain' => 'optional_chain'
		),
	),
);

$wgCirrusSearchRescoreProfiles['relforge_inclinks'] = array(
	'supported_namespaces' => 'all',
	'rescore' => array(
		array(
			'window' => 8192,
			'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
			'query_weight' => 1.0,
			'rescore_query_weight' => 1.0,
			'score_mode' => 'total',
			'type' => 'function_score',
			'function_chain' => 'wsum_inclinks'
		),
		array(
			'window' => 8192,
			'window_size_override' => 'CirrusSearchFunctionRescoreWindowSize',
			'query_weight' => 1.0,
			'rescore_query_weight' => 1.0,
			'score_mode' => 'multiply',
			'type' => 'function_score',
			'function_chain' => 'optional_chain'
		),
	),
);

$wgCirrusSearchRescoreFunctionScoreChains['wsum_pageviews_and_inclinks'] = array(
	'score_mode' => 'sum',
	'boost_mode' => 'sum',
	'functions' => array(
		array(
			'type' => 'satu',
			'weight' => array(
				'value' => 1.8,
				'config_override' => 'CirrusSearchPageViewsW',
				'uri_param_override' => 'cirrusPageViewsW',
			),
			'params' => array(
				'field' => 'popularity_score',
				'k' => array(
					'value' => 0.0000007,
					'config_override' => 'CirrusSearchPageViewsK',
					'uri_param_override' => 'cirrusPageViewsK',
				),
				'a' => array(
					'value' => 1,
					'config_override' => 'CirrusSearchPageViewsA',
					'uri_param_override' => 'cirrusPageViewsA',
				),
			),
		),
		array(
			'type' => 'satu',
			'weight' => array(
				'value' => 0.6,
				'config_override' => 'CirrusSearchIncLinksW',
				'uri_param_override' => 'cirrusIncLinkssW',
			),
			'params' => array(
				'field' => 'incoming_links',
				'k' => array(
					'value' => 10,
					'config_override' => 'CirrusSearchIncLinksK',
					'uri_param_override' => 'cirrusIncLinksK',
				),
				'a' => array(
					'value' => 1,
					'config_override' => 'CirrusSearchIncLinksA',
					'uri_param_override' => 'cirrusIncLinksA',
				),
			),
		),
		array(
			'type' => 'satu',
			'weight' => array(
				'value' => 0.6,
				'config_override' => 'CirrusSearchSizeW',
				'uri_param_override' => 'cirrusSearchSizeW',
			),
			'params' => array(
				'field' => 'text.word_count',
				'k' => array(
					'value' => 10,
					'config_override' => 'CirrusSearchSizeK',
					'uri_param_override' => 'cirrusSizesK',
				),
				'a' => array(
					'value' => 1,
					'config_override' => 'CirrusSearchSizeA',
					'uri_param_override' => 'cirrusSizeA',
				),
			),
		),
	),
);

$wgCirrusSearchRescoreFunctionScoreChains['wsum_inclinks'] = array(
	'functions' => array(
		array(
			'type' => 'satu',
			'params' => array(
				'field' => 'incoming_links',
				'weight' => array(
					'value' => 1.2,
					'config_override' => 'CirrusSearchIncLinksAloneW',
					'uri_param_override' => 'cirrusIncLinksAloneW',
				),
				'k' => array(
					'value' => 10,
					'config_override' => 'CirrusSearchIncLinksAloneK',
					'uri_param_override' => 'cirrusIncLinksAloneK',
				),
				'a' => array(
					'value' => 1,
					'config_override' => 'CirrusSearchIncLinksAloneA',
					'uri_param_override' => 'cirrusIncLinksAloneA',
				),
			),
		),
	),
);
