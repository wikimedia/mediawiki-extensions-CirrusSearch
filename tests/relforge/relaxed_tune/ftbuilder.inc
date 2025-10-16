<?php

global $wgCirrusSearchRelaxedTuneProfile;

$wgCirrusSearchFullTextQueryBuilderProfiles['relforge'] = [
	'builder_class' => \CirrusSearch\Query\FullTextSimpleMatchQueryBuilder::class,
	'settings' => [
		'default_min_should_match' => '1',
		'default_query_type' => 'most_fields',
		'default_stem_weight' => 3.0,
		'filter' => [
			'type' => 'default',
			'settings' => [
				// 1-3: match all, 4-5: match all but 1, 5+ match 50%
				'all' => [
					'minimum_should_match' => '3<-1 5<50%',
				],
				'all.plain' => [
					'minimum_should_match' => '3<-1 5<50%',
				],
			],
		],
		'fields' => $wgCirrusSearchRelaxedTuneProfile['fields'],
		'phrase_rescore_fields' => [
			// very low (don't forget it's multiplied by 10 by default)
			// Use the all field to avoid loading positions on another field,
			// score is roughly the same when used on text
			'all' => 0.03,
			'all.plain' => 0.01,
		],
	],
];

