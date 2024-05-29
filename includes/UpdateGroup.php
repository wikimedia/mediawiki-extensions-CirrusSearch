<?php

namespace CirrusSearch;

class UpdateGroup {
	// Set of clusters receiving page updates
	public const PAGE = "page";
	// Set of clusters receiving archive updates
	public const ARCHIVE = "archive";
	// Set of clusters being checked by saneitizer
	public const CHECK_SANITY = "check_sanity";
	// Set of clusters being fixed by saneitizer
	public const SANEITIZER = "saneitize";
	// Set of clusters receiving weighted_tags updates
	public const WEIGHTED_TAGS = "weighted_tags";
}
