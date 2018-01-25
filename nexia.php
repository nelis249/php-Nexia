<?php

class Nexia
{
	/**
     * @var string $LoginId - username for the Nexia site.
     */
	protected $LoginId;
	/**
     * @var string $Credential - password for the Nexia site.
     */
	protected $Credential;
	/**
     * @var string $HouseId - the house id defined by the Nexia site. To find this logon to the site and go to
	 * one of the sub areas then look at the URL. It will resemble this: https://www.mynexia.com/houses/888888/climate/index
	 * In this example the house id is 888888
     */
	protected $HouseId;
	
	// constants, shouldn't have to modify
	private $nexiaUrl = "https://www.mynexia.com";
	private $cookieFile;
	private $cookieExpiry = 600;
	
	/**
     * Create a new instance and authenticates the session establishing the cookie data.
     *
     * @param string $login Username for Nexia site
     * @param string $cred Password for Nexia site
     * @param string $houseId House id from Nexia site
     * @param string $forceNew Specify true to ignore cookie data and force new authentication
     */
	public function __construct($login, $cred, $houseId, $forceNew = false)
    {
        $this->LoginId = $login;
        $this->Credential = $cred;
        $this->HouseId = $houseId;
		$this->cookieFile = dirname(__FILE__)."\\nexia_cookies.txt";
        $this->Initialize($forceNew);
    }
	
	/**
     * Method to initialize the session and set up the cookies for the subsequent calls.
	 *
     * @param bool $forceNew Specify true to force renewal of the credentials.
     */
	protected function Initialize($forceNew)
	{
		$crl = $this->GetCurlObject();
		
		if(file_exists($this->cookieFile))
		{
			// check cookie timestamp to see if it's within 10 mins or is forcing
			if($forceNew || (time()-filemtime($this->cookieFile) > $this->cookieExpiry))
			{
				unlink($this->cookieFile);	// delete file
			}
		}
			
		if (!file_exists($this->cookieFile)) {
			curl_setopt ($crl, CURLOPT_URL, $this->nexiaUrl."/login");
			curl_setopt ($crl, CURLOPT_CUSTOMREQUEST, 'GET');
			$ret = curl_exec($crl);

			// check if web call is even populated in the event the machine doesn't have internet connectivity
			if (strpos($ret, 'authenticity_token') == false) {
				throw new Exception("Could not contact login page. Check internet connectivity.");
			}
			
			// get auth token from login page to post during authentication
			preg_match('/name="authenticity_token" type="hidden" value="(?<authKey>.*)" \/><\/div>/',$ret,$matches);
			$authKey = $matches['authKey'];

			$data = array('utf8'=>'?',
						'authenticity_token'=>$authKey,
						'login'=>$this->LoginId,
						'password'=>$this->Credential);

			// post authentication
			curl_setopt ($crl, CURLOPT_URL, $this->nexiaUrl."/session");
			curl_setopt ($crl, CURLOPT_CUSTOMREQUEST, 'POST');
			// only use writable cookie or the session will invalidate
			curl_setopt ($crl, CURLOPT_COOKIEJAR, $this->cookieFile);	// use cookies to store session data
			curl_setopt ($crl, CURLOPT_POSTFIELDS, http_build_query($data));
			// executing established the cookies for the session
			$ret = curl_exec($crl);
		
			// after logon do a get on the main URL to verify credentials
			// if 302 the credentials are invalid andf is redirected to the login page
			// if 200 is returned then credentials were good
			curl_setopt ($crl, CURLOPT_URL, $this->nexiaUrl);
			curl_setopt ($crl, CURLOPT_CUSTOMREQUEST, 'GET');
			$ret = curl_exec($crl);
			$statuscode = curl_getinfo($crl, CURLINFO_HTTP_CODE);
			curl_close($crl);

			if($statuscode!=200)
			{
				throw new Exception("Invalid login. Check credentials for Nexia.");
			}
			// finally get the house id from the page and verify it
			preg_match('/window.Nexia.modes.houseId = (?<houseId>.*);/',$ret,$matches);
			
			// one might argue why not just get the house id? Well for subsequent calls we don't want to have
			// to keep polling for the information so it is only validated that it's correct. This saves
			// a 'GET' and parse for every call
			if($this->HouseId != $matches['houseId'])
			{
				throw new Exception("The house id specified [".$this->HouseId."] does not match the Nexia account [".$matches['houseId']."].");
			}
		}
	}
	
	/**
     * Method to get the current temperature of a specific thermostat.
     *
     * @param int/string $thermoNameOrIndex Specify the zero indexed number for a thermostat or the friendly name.
	 *
     * @return string The current temperature on the specified thermostat.
     */
	public function GetThermostatTemperature($thermoNameOrIndex)
	{
		$json = $this->GetThermostatData();
		if(!is_numeric($thermoNameOrIndex)) {
			$thermoNameOrIndex = $this->GetIndexByName($thermoNameOrIndex, $json);
		}
		if($thermoNameOrIndex > (sizeof($json)-1))
		{
			throw new Exception("The specified thermostat out of range. There are only ".sizeof($json)." devices detected.");
		}
		
		return $json[$thermoNameOrIndex]->zones[0]->temperature;
	}
	
	/**
     * Method to get the target temperature of a specific thermostat [e.g. what the thermostat is set to].
     *
     * @param int/string $thermoNameOrIndex Specify the zero indexed number for a thermostat or the friendly name.
	 *
     * @return string The current set point temperature on the specified thermostat.
     */
	public function GetThermostatSetPoint($thermoNameOrIndex)
	{
		$json = $this->GetThermostatData();
		if(!is_numeric($thermoNameOrIndex)) {
			$thermoNameOrIndex = $this->GetIndexByName($thermoNameOrIndex, $json);
		}
		if($thermoNameOrIndex > (sizeof($json)-1))
		{
			throw new Exception("The specified thermostat out of range. There are only ".sizeof($json)." devices detected.");
		}
		if($json[$thermoNameOrIndex]->operating_mode=="COOL")
		{
			return $json[$thermoNameOrIndex]->zones[0]->cooling_setpoint;
		}
		if($json[$thermoNameOrIndex]->operating_mode=="HEAT")
		{
			return $json[$thermoNameOrIndex]->zones[0]->heating_setpoint;
		}
		return -1;
	}
	
	/**
     * Method to get a reformatted version of the json data versus the giant one from nexia that has a bunch of unneeded info
	 *
     * @return json A shortened version of a JSON object for the thermostats.
     */
	public function GetThermostatJsonData()
	{
		$json = $this->GetThermostatData();

		$therms = array();
		$position = 0;
		foreach($json as $thermo)
		{
			$extended = new stdClass;  
			$extended->name=$thermo->name;  
			$extended->id=$position++;
			$extended->temperature=$thermo->zones[0]->temperature;
			$extended->mode=$thermo->operating_mode;
			if($extended->mode=="COOL")
			{
				$extended->setpoint=$thermo->zones[0]->cooling_setpoint;
			}
			if($extended->mode=="HEAT")
			{
				$extended->setpoint=$thermo->zones[0]->heating_setpoint;
			}
			array_push($therms,$extended);
		}
		return json_encode($therms);
	}
	
	/**
     * Method to set the temperature on a thermostat
     *
     * @param int/string $thermoNameOrIndex Specify the zero indexed number for a thermostat or the friendly name.
     * @param int $temp Temperature to set on the specified thermostat.
	 *
     * @return bool True if successful; otherwise false.
     */
	public function SetTemperature($thermoNameOrIndex, $temp)
	{
		$json = $this->GetThermostatData();
		if(!is_numeric($thermoNameOrIndex)) {
			$thermoNameOrIndex = $this->GetIndexByName($thermoNameOrIndex, $json);
		}
		if($thermoNameOrIndex > (sizeof($json)-1))
		{
			throw new Exception("The specified thermostat out of range. There are only ".sizeof($json)." devices detected.");
		}
		
		$zone = $json[$thermoNameOrIndex]->zones[0];
		// the nexia PUT requires that both cool and heat are sent to it even if only changing one of them
		if($json[$thermoNameOrIndex]->operating_mode=="COOL")
		{
			$data = array(
					'cooling_setpoint'=>(int)$temp,
					'cooling_integer'=>$temp,
					'heating_setpoint'=>(int)$zone->heating_setpoint,	// send same data
					'heating_integer'=>$zone->heating_setpoint
					);
		}
		if($json[$thermoNameOrIndex]->operating_mode=="HEAT")
		{
			$data = array(
					'cooling_setpoint'=>(int)$zone->cooling_setpoint,	// send same data
					'cooling_integer'=>$zone->cooling_setpoint,
					'heating_setpoint'=>(int)$temp,
					'heating_integer'=>$temp
					);
		}
		
		$crl = $this->GetCurlObject();
		curl_setopt ($crl, CURLOPT_CUSTOMREQUEST, 'PUT');
		curl_setopt ($crl, CURLOPT_URL, $this->nexiaUrl."/houses/".$this->HouseId."/xxl_zones/".$zone->id."/setpoints");
		// put has to be content-type application/json or the page will not accept it
		curl_setopt ($crl, CURLOPT_HTTPHEADER, array(
			'Content-Type:application/json',
			'Accept-Language:en-US,en,q=0.8',
			'Connection:keep-alive',
			'User-Agent:Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36'
		));
		curl_setopt ($crl, CURLOPT_POSTFIELDS, json_encode($data));
		curl_exec($crl);
		$statuscode = curl_getinfo($crl, CURLINFO_HTTP_CODE);
		curl_close($crl);
		if($statuscode!=200)
		{
			//throw new Exception("Set temperature did not succeed [result ".$statuscode."].");
			return false;
		}
		return true;
	}
	
	/**
     * Method to get a thermostat index by friendly display name.
     *
     * @param string $thermoName Specify the thermostat friendly name.
     * @param json $jsonData Provide the json data from the GetThermostatData() call.
	 *
     * @return int The index position of the thermostat based on the friendly name provided.
     */
	private function GetIndexByName($thermoName, $jsonData)
	{
		// if not a number maybe a name so check through them
		$targetName = strtolower($thermoName);
		$position = 0;
		foreach($jsonData as $thermo)
		{
			if($targetName == strtolower($thermo->name))
			{
				return $position;
			}
			$position++;
		}
		throw new Exception("Could not find the thermostat with the name '".$thermoName."'.");
	}
	
	/**
     * Method to get the thermostat json object that contains the current state of all the thermostats on the account.
	 * The data is imbedded in a giant java script which is then processed on the web page so look for the java script
	 * and pull out the json data
	 *
     * @return json The full raw JSON object from Nexia.
     */
	private function GetThermostatData()
	{
		$crl = $this->GetCurlObject();
		curl_setopt ($crl, CURLOPT_CUSTOMREQUEST, 'GET');
		curl_setopt ($crl, CURLOPT_URL, $this->nexiaUrl."/houses/".$this->HouseId."/climate/index");
		$ret = curl_exec($crl);
		$statuscode = curl_getinfo($crl, CURLINFO_HTTP_CODE);
		if($statuscode!=200)
		{
			$this->Initialize(true);
			return $this->GetThermostatData();
			//throw new Exception("The session appears to be stale. Invalid status code returned [".$statuscode."].");
		}
			
		preg_match("/Nexia.XXL.run\('".$this->HouseId."', \[{(?<jsonData>.*)}\]\);/",$ret,$matches);
		$data = "[{".$matches['jsonData']."}]";

		curl_close($crl);

		$stack = json_decode($data);
		return $stack;
	}
	
	/**
     * Method to get the standard curl object with the proper headers.
	 *
     * @return object The curl object for web calls.
     */
	private function GetCurlObject()
	{
		$crl = curl_init();

		curl_setopt ($crl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt ($crl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt ($crl, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt ($crl, CURLOPT_COOKIEFILE, $this->cookieFile);
		curl_setopt ($crl, CURLOPT_HTTPHEADER, array(
			'Content-Type:application/x-www-form-urlencoded',
			'Accept:text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
			'Accept-Language:en-US,en,q=0.8',
			'Cache-Control:max-age=0',
			'Connection:keep-alive',
			'User-Agent:Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36'
		));
		return $crl;
	}
}

?>