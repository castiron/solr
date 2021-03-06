<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2009-2012 Ingo Renner <ingo@typo3.org>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/


# TSFE initialization

tslib_eidtools::connectDB();
$pageId     = filter_var(t3lib_div::_GET('id'), FILTER_SANITIZE_NUMBER_INT);
$languageId = filter_var(
	t3lib_div::_GET('L'),
	FILTER_VALIDATE_INT,
	array('options' => array('default' => 0, 'min_range' => 0))
);

$TSFE = t3lib_div::makeInstance('tslib_fe', $GLOBALS['TYPO3_CONF_VARS'], $pageId, 0, TRUE);
$TSFE->initFEuser();
$TSFE->initUserGroups();
// load TCA
if (version_compare(TYPO3_version, '6.1-dev', '>=')) {
	\TYPO3\CMS\Core\Core\Bootstrap::getInstance()->loadCachedTca();
} else {
	$TSFE->includeTCA();
}
$TSFE->sys_page = t3lib_div::makeInstance('t3lib_pageSelect');
$TSFE->rootLine = $TSFE->sys_page->getRootLine($pageId, '');
$TSFE->initTemplate();
$TSFE->getConfigArray();



$TSFE->sys_language_uid = $languageId;

$solrConfiguration = Tx_Solr_Util::getSolrConfiguration();

#--- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---

# Building Suggest Query
$q = trim(t3lib_div::_GP('termLowercase'));

$isOpenSearchRequest = FALSE;
if ('OpenSearch' == t3lib_div::_GET('format')) {
	$isOpenSearchRequest = TRUE;
	$q = t3lib_div::_GET('q');
}

$allowedSites = Tx_Solr_Util::resolveSiteHashAllowedSites(
	$pageId,
	$solrConfiguration['search.']['query.']['allowedSites']
);

$suggestQuery = t3lib_div::makeInstance('Tx_Solr_SuggestQuery', $q);
$suggestQuery->setUserAccessGroups(explode(',', $TSFE->gr_list));
$suggestQuery->setSiteHashFilter($allowedSites);
$suggestQuery->setOmitHeader();

$additionalFilters = t3lib_div::_GET('filters');
if (!empty($additionalFilters)) {
	$additionalFilters = json_decode($additionalFilters);
	foreach ($additionalFilters as $additionalFilter) {
		$suggestQuery->addFilter($additionalFilter);
	}
}

#--- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- --- ---

	// Search
$solr   = t3lib_div::makeInstance('Tx_Solr_ConnectionManager')->getConnectionByPageId(
	$pageId,
	$languageId
);
$search = t3lib_div::makeInstance('Tx_Solr_Search', $solr);

if ($search->ping()) {
	$results = json_decode($search->search($suggestQuery, 0, 0)->getRawResponse());
	$facetSuggestions = $results->facet_counts->facet_fields->{$solrConfiguration['suggest.']['suggestField']};
	$facetSuggestions = get_object_vars($facetSuggestions);

	$suggestions = array();
	foreach($facetSuggestions as $partialKeyword => $value){
		$suggestionKey = trim($suggestQuery->getKeywords() . ' ' . $partialKeyword);
		$suggestions[$suggestionKey] = $facetSuggestions[$partialKeyword];
	}

	if ($isOpenSearchRequest) {
		$suggestions = array(
			$q,
			array_keys($suggestions)
		);
	}

	$ajaxReturnData = json_encode($suggestions);
} else {
	$ajaxReturnData = json_encode(array('status' => FALSE));
}

header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Length: ' . strlen($ajaxReturnData));
header('Content-Type: application/json; charset=utf-8');
header('Content-Transfer-Encoding: 8bit');
echo $ajaxReturnData;

?>
