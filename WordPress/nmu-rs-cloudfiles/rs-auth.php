<?php
/*
 * Get an auth token and populate the necessary public and internal service URLs
 */
class NMURackspaceAuth {
	private $_auth_post_body = array(
		'auth' => array(
			'RAX-KSKEY:apiKeyCredentials' => array(
				'username' => 'xxx',
				'apiKey' => 'xxx'
			)
		)
	);
	private $_curl_auth_request_options = array(
		CURLOPT_URL => 'https://identity.api.rackspacecloud.com/v2.0/tokens',
		CURLOPT_HTTPHEADER => array(
			'Content-Type: application/json'
		),
		CURLOPT_RETURNTRANSFER => true
	);
	private $_token;
	private $_tokenExpires;
	
	public $serviceURL;
	public $clientId;

	function __construct($serviceName = 'cloudFiles', $region = 'ORD', $internal = true) {
		$curl_session = curl_init();
		curl_setopt_array($curl_session, $this->_curl_auth_request_options);
		curl_setopt($curl_session, CURLOPT_POSTFIELDS, json_encode($this->_auth_post_body, JSON_FORCE_OBJECT));
		$result = curl_exec($curl_session);
		curl_close($curl_session);

		$auth_response = json_decode($result);
		$this->_token = $auth_response->access->token->id;
		$this->_tokenExpires = $auth_response->access->token->expires;
		foreach ($auth_response->access->serviceCatalog as $service) {
			if ($service->name == $serviceName) {
				foreach ($service->endpoints as $endpoint) {
					if ($endpoint->region == $region) {
						if ($internal) {
							$this->serviceURL = $endpoint->internalURL;
						} else {
							$this->serviceURL = $endpoint->publicURL;
						}
					}
				}
			}
		}

		if ($serviceName == 'cloudQueues') {
			$this->clientId = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
				// 32 bits for "time_low"
				mt_rand(0, 0xffff), mt_rand(0, 0xffff),	
				// 16 bits for "time_mid"
				mt_rand(0, 0xffff),
				// 16 bits for "time_hi_and_version",
				// four most significant bits holds version number 4
				mt_rand(0, 0x0fff) | 0x4000,
				// 16 bits, 8 bits for "clk_seq_hi_res",
				// 8 bits for "clk_seq_low",
				// two most significant bits holds zero and one for variant DCE1.1
				mt_rand(0, 0x3fff) | 0x8000,
				// 48 bits for "node"
				mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
			);
		}	
	}

	/**
	* accessor for auth token, checks for expiration and reauthorizes if needed
	* @return string the Rackspace API auth token
	*/
	public function getToken() {
		if (time() < strtotime($this->_tokenExpires)) {
			return $this->_token;
		} else {
			$curl_session = curl_init();
			curl_setopt_array($curl_session, $this->_curl_auth_request_options);
			curl_setopt($curl_session, CURLOPT_POSTFIELDS, json_encode($this->_auth_post_body, JSON_FORCE_OBJECT));
			$result = curl_exec($curl_session);
			curl_close($curl_session);

			$auth_response = json_decode($result);
			$this->_token = $auth_response->access->token->id;
			$this->_tokenExpires = $auth_response->access->token->expires;

			return $this->_token;
		}
	}

	/**
 	* convenience function for making cURL requests
	* @param array an array of curl options as specified in the docs for curl_setopt_array()
	* @return array containing result, if any, http response code and the full headers 
	*/

	public static function docURLRequest($curl_options) {
		$curl_session = curl_init();
		curl_setopt_array($curl_session, $curl_options);
		$result = curl_exec($curl_session);
		
		$response_code = curl_getinfo($curl_session, CURLINFO_HTTP_CODE);
		$header_size = curl_getinfo($curl_session, CURLINFO_HEADER_SIZE);
		$header = substr($result, 0, $header_size);
		
		if ($response_code == 100) {
			//need to parse headers ourselves for the response after the HTTP/1.1 100 Continue			
			preg_match_all('|HTTP/\d\.\d\s+(\d+)\s+.*|', $header, $matches);
			$response_code = array_pop($matches[1]);
		}

		curl_close($curl_session);

		return array('result' => $result, 'http_response' => $response_code, 'header' => $header);
	}
}