<?php

namespace CirrusSearch\Query;

/**
 * This class has been refactored into ArticlePredictionKeyword.
 *
 * We preserve it only to avoid breaking extensions that required direct access
 * to ArticleTopicFeature::TERMS_TO_LABELS.
 */
class ArticleTopicFeature {
	/**
	 * Maps search terms to tags.
	 *
	 * Structure:
	 * - Keys: search terms
	 * - Values: tags
	 *
	 * @var array<string, string>
	 */
	public const TERMS_TO_LABELS = [
		'biography' => 'Culture.Biography.Biography*',
		'women' => 'Culture.Biography.Women',
		'food-and-drink' => 'Culture.Food and drink',
		'internet-culture' => 'Culture.Internet culture',
		'linguistics' => 'Culture.Linguistics',
		'literature' => 'Culture.Literature',
		'books' => 'Culture.Media.Books',
		'entertainment' => 'Culture.Media.Entertainment',
		'films' => 'Culture.Media.Films',
		'media' => 'Culture.Media.Media*',
		'music' => 'Culture.Media.Music',
		'radio' => 'Culture.Media.Radio',
		'software' => 'Culture.Media.Software',
		'television' => 'Culture.Media.Television',
		'video-games' => 'Culture.Media.Video games',
		'performing-arts' => 'Culture.Performing arts',
		'philosophy-and-religion' => 'Culture.Philosophy and religion',
		'sports' => 'Culture.Sports',
		'architecture' => 'Culture.Visual arts.Architecture',
		'comics-and-anime' => 'Culture.Visual arts.Comics and Anime',
		'fashion' => 'Culture.Visual arts.Fashion',
		'visual-arts' => 'Culture.Visual arts.Visual arts*',
		'geographical' => 'Geography.Geographical',
		'africa' => 'Geography.Regions.Africa.Africa*',
		'central-africa' => 'Geography.Regions.Africa.Central Africa',
		'eastern-africa' => 'Geography.Regions.Africa.Eastern Africa',
		'northern-africa' => 'Geography.Regions.Africa.Northern Africa',
		'southern-africa' => 'Geography.Regions.Africa.Southern Africa',
		'western-africa' => 'Geography.Regions.Africa.Western Africa',
		'central-america' => 'Geography.Regions.Americas.Central America',
		'north-america' => 'Geography.Regions.Americas.North America',
		'south-america' => 'Geography.Regions.Americas.South America',
		'asia' => 'Geography.Regions.Asia.Asia*',
		'central-asia' => 'Geography.Regions.Asia.Central Asia',
		'east-asia' => 'Geography.Regions.Asia.East Asia',
		'north-asia' => 'Geography.Regions.Asia.North Asia',
		'south-asia' => 'Geography.Regions.Asia.South Asia',
		'southeast-asia' => 'Geography.Regions.Asia.Southeast Asia',
		'west-asia' => 'Geography.Regions.Asia.West Asia',
		'eastern-europe' => 'Geography.Regions.Europe.Eastern Europe',
		'europe' => 'Geography.Regions.Europe.Europe*',
		'northern-europe' => 'Geography.Regions.Europe.Northern Europe',
		'southern-europe' => 'Geography.Regions.Europe.Southern Europe',
		'western-europe' => 'Geography.Regions.Europe.Western Europe',
		'oceania' => 'Geography.Regions.Oceania',
		'business-and-economics' => 'History and Society.Business and economics',
		'education' => 'History and Society.Education',
		'history' => 'History and Society.History',
		'military-and-warfare' => 'History and Society.Military and warfare',
		'politics-and-government' => 'History and Society.Politics and government',
		'society' => 'History and Society.Society',
		'transportation' => 'History and Society.Transportation',
		'biology' => 'STEM.Biology',
		'chemistry' => 'STEM.Chemistry',
		'computing' => 'STEM.Computing',
		'earth-and-environment' => 'STEM.Earth and environment',
		'engineering' => 'STEM.Engineering',
		'libraries-and-information' => 'STEM.Libraries & Information',
		'mathematics' => 'STEM.Mathematics',
		'medicine-and-health' => 'STEM.Medicine & Health',
		'physics' => 'STEM.Physics',
		'stem' => 'STEM.STEM*',
		'space' => 'STEM.Space',
		'technology' => 'STEM.Technology',
	];
}
