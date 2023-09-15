# php-Nexia
A simple php class to interact with Trane (Nexia) thermostats. I didn't feel like rebranding everything to 'Trane' and their API still has Nexia in the output so just leaving the naming as is.

# Example
$nexia = new Nexia($username,$password,$houseId);

$nexia->SetTemperature("livingroom","75");

NOTE1: The house id defined by the Nexia site. To find this id, logon to the site and go to
	     one of the sub areas then look at the URL. It will resemble this: https://tranehome.com/houses/888888/climate/index
	     In this example the house id is 888888
      
NOTE2: This class is strictly for thermostats, not all of the other junk on the Nexia site.
