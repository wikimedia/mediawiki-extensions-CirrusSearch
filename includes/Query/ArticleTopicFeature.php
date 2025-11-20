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
		'food-and-drink' => 'Culture.Food_and_drink',
		'internet-culture' => 'Culture.Internet_culture',
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
		'video-games' => 'Culture.Media.Video_games',
		'performing-arts' => 'Culture.Performing_arts',
		'philosophy-and-religion' => 'Culture.Philosophy_and_religion',
		'sports' => 'Culture.Sports',
		'architecture' => 'Culture.Visual_arts.Architecture',
		'comics-and-anime' => 'Culture.Visual_arts.Comics_and_Anime',
		'fashion' => 'Culture.Visual_arts.Fashion',
		'visual-arts' => 'Culture.Visual_arts.Visual_arts*',
		'geographical' => 'Geography.Geographical',
		'africa' => 'Geography.Regions.Africa.Africa*',
		'central-africa' => 'Geography.Regions.Africa.Central_Africa',
		'eastern-africa' => 'Geography.Regions.Africa.Eastern_Africa',
		'northern-africa' => 'Geography.Regions.Africa.Northern_Africa',
		'southern-africa' => 'Geography.Regions.Africa.Southern_Africa',
		'western-africa' => 'Geography.Regions.Africa.Western_Africa',
		'central-america' => 'Geography.Regions.Americas.Central_America',
		'north-america' => 'Geography.Regions.Americas.North_America',
		'south-america' => 'Geography.Regions.Americas.South_America',
		'asia' => 'Geography.Regions.Asia.Asia*',
		'central-asia' => 'Geography.Regions.Asia.Central_Asia',
		'east-asia' => 'Geography.Regions.Asia.East_Asia',
		'north-asia' => 'Geography.Regions.Asia.North_Asia',
		'south-asia' => 'Geography.Regions.Asia.South_Asia',
		'southeast-asia' => 'Geography.Regions.Asia.Southeast_Asia',
		'west-asia' => 'Geography.Regions.Asia.West_Asia',
		'eastern-europe' => 'Geography.Regions.Europe.Eastern_Europe',
		'europe' => 'Geography.Regions.Europe.Europe*',
		'northern-europe' => 'Geography.Regions.Europe.Northern_Europe',
		'southern-europe' => 'Geography.Regions.Europe.Southern_Europe',
		'western-europe' => 'Geography.Regions.Europe.Western_Europe',
		'oceania' => 'Geography.Regions.Oceania',
		'business-and-economics' => 'History_and_Society.Business_and_economics',
		'education' => 'History_and_Society.Education',
		'history' => 'History_and_Society.History',
		'military-and-warfare' => 'History_and_Society.Military_and_warfare',
		'politics-and-government' => 'History_and_Society.Politics_and_government',
		'society' => 'History_and_Society.Society',
		'transportation' => 'History_and_Society.Transportation',
		'biology' => 'STEM.Biology',
		'chemistry' => 'STEM.Chemistry',
		'computing' => 'STEM.Computing',
		'earth-and-environment' => 'STEM.Earth_and_environment',
		'engineering' => 'STEM.Engineering',
		'libraries-and-information' => 'STEM.Libraries_&_Information',
		'mathematics' => 'STEM.Mathematics',
		'medicine-and-health' => 'STEM.Medicine_&_Health',
		'physics' => 'STEM.Physics',
		'stem' => 'STEM.STEM*',
		'space' => 'STEM.Space',
		'technology' => 'STEM.Technology',
	];
}
