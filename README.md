# php-Nexia
A simple php class to interact with Nexia thermostats

# Example
$nexia = new Nexia($username,$password,$houseId);

$nexia->SetTemperature("livingroom","75");
