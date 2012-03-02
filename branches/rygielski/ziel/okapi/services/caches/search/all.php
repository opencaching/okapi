<?php

# This method is the simplest of all. It just returns all cashes, in any order.
# Results might be limited only with the "standard filtering arguments",
# implemented in the OkapiSearchAssistant::get_common_search_params.
#
# Its existance is intentional - though a bit inpractical, it serves as a
# reference base for every other search method which might use "standard
# filters" (those defined in OkapiSearchAssistant::get_common_search_params).

namespace okapi\services\caches\search\all;

use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\services\caches\search\SearchAssistant;

require_once 'searching.inc.php';

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 1
		);
	}
	
	public static function call(OkapiRequest $request)
	{
		# We declared Token to be 'optional' in the OkapiRequest above. This means,
		# that OKAPI will pass on requests NOT signed with an Access Token. We may
		# check if this requests is signed with: $request->token != null.

		$search_params = SearchAssistant::get_common_search_params($request);
		$search_params['extra_tables'] = array();
		$result = SearchAssistant::get_common_search_result($search_params);
		
		return Okapi::formatted_response($request, $result);
	}
}
