<?php

// profiles tuned with https://gitlab.wikimedia.org/dcausse/retrieval-tune
$relaxed_model_shape = "all_field_only";
global $wgCirrusSearchRelaxedTuneProfile;
$wgCirrusSearchRelaxedTuneProfile = json_decode( file_get_contents( __DIR__ . "/$relaxed_model_shape.json" ), true );
