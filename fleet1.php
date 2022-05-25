<?php

define('INSIDE', true);

$_EnginePath = './';

include($_EnginePath.'common.php');

include($_EnginePath . 'modules/flightControl/_includes.php');

use UniEngine\Engine\Includes\Helpers\Common\Collections;
use UniEngine\Engine\Modules\FlightControl;

loggedCheck();

if((!isset($_POST['sending_fleet']) || $_POST['sending_fleet'] != '1') && (!isset($_POST['gobackUsed']) || $_POST['gobackUsed'] != '1'))
{
    header('Location: fleet.php');
    safeDie();
}

includeLang('fleet');

$Now = time();
$_Lang['Now'] = $Now;
$ErrorTitle = &$_Lang['fl_error'];
$Hide = ' class="hide"';

$shipsDetails = [];

$Fleet = [
    'count' => 0,
    'storage' => 0,
    'FuelStorage' => 0,
];

if(MORALE_ENABLED)
{
    Morale_ReCalculate($_User, $Now);
}

if(isset($_POST['gobackUsed']))
{
    $_POST['quickres'] = $_POST['useQuickRes'];
    $_POST['target_mission'] = (isset($_POST['mission']) ? $_POST['mission'] : 0);
    $_POST['getacsdata'] = (isset($_POST['acs_id']) ? $_POST['acs_id'] : 0);

    $_Set_DefaultSpeed = $_POST['speed'];

    if (!empty($_POST['FleetArray'])) {
        $_POST['ship'] = FlightControl\Utils\Inputs\normalizeGobackFleetArrayInput([
            'fleetArray' => $_POST['FleetArray'],
        ]);
    }

    $GoBackVars = [
        'resource1' => $_POST['resource1'],
        'resource2' => $_POST['resource2'],
        'resource3' => $_POST['resource3'],
        'usequantumgate' => (isset($_POST['usequantumgate']) ? $_POST['usequantumgate'] : null),
        'expeditiontime' => (isset($_POST['expeditiontime']) ? $_POST['expeditiontime'] : null),
        'holdingtime' => (isset($_POST['holdingtime']) ? $_POST['holdingtime'] : null)
    ];
}
if(!empty($_POST['gobackVars']))
{
    $_Lang['P_GoBackVars'] = json_decode(base64_decode($_POST['gobackVars']), true);
    if((array)$_Lang['P_GoBackVars'] === $_Lang['P_GoBackVars'])
    {
        if(!empty($GoBackVars))
        {
            $GoBackVars = array_merge($GoBackVars, $_Lang['P_GoBackVars']);
        }
        else
        {
            $GoBackVars = $_Lang['P_GoBackVars'];
        }
    }
}
if(!empty($GoBackVars))
{
    $_Lang['P_GoBackVars'] = base64_encode(json_encode($GoBackVars));
}

if(!empty($_POST['gobackVars']))
{
    $_POST['gobackVars'] = json_decode(base64_decode($_POST['gobackVars']), true);
    $_Set_DefaultSpeed = $_POST['gobackVars']['speed'];
}

// Management of ShipsList
if (!empty($_POST['ship'])) {
    $fleetArrayParsingResult = FlightControl\Utils\Validators\parseFleetArray([
        'fleet' => $_POST['ship'],
        'planet' => &$_Planet,
        'isFromDirectUserInput' => true,
    ]);

    if (!$fleetArrayParsingResult['isValid']) {
        $firstValidationError = $fleetArrayParsingResult['errors'][0];

        $errorMessage = null;
        switch ($firstValidationError['errorCode']) {
            case 'INVALID_SHIP_ID':
                $errorMessage = $_Lang['fl1_BadShipGiven'];
                break;
            case 'SHIP_WITH_NO_ENGINE':
                $errorMessage = $_Lang['fl1_CantSendUnflyable'];
                break;
            case 'INVALID_SHIP_COUNT':
                $errorMessage = $_Lang['fleet_generic_errors_invalidshipcount'];
                break;
            case 'SHIP_COUNT_EXCEEDS_AVAILABLE':
                $errorMessage = $_Lang['fl1_NoEnoughShips'];
                break;
            default:
                $errorMessage = $_Lang['fleet_generic_errors_unknown'];
                break;
        }

        message($errorMessage, $ErrorTitle, 'fleet.php', 3);
    }

    foreach ($_POST['ship'] as $ShipID => $ShipCount) {
        $ShipID = intval($ShipID);
        $ShipCount = floor(str_replace('.', '', $ShipCount));

        if ($ShipCount <= 0) {
            continue;
        }

        $Fleet['array'][$ShipID] = $ShipCount;
        $Fleet['count'] += $ShipCount;

        $allShipsOfTypeStorage = getShipsStorageCapacity($ShipID) * $ShipCount;

        if (canShipPillage($ShipID)) {
            $Fleet['storage'] += $allShipsOfTypeStorage;
        } else {
            $Fleet['FuelStorage'] += $allShipsOfTypeStorage;
        }

        $shipSpeed = getShipsCurrentSpeed($ShipID, $_User);
        $shipConsumption = getShipsCurrentConsumption($ShipID, $_User);
        $allShipsConsumption = ($shipConsumption * $ShipCount);

        $shipsDetails[$ShipID] = [
            'speed' => $shipSpeed,
            'totalConsumptionOfShipType' => (string) $allShipsConsumption,
        ];
    }
}

if($Fleet['count'] <= 0)
{
    message($_Lang['fl1_NoShipsGiven'], $ErrorTitle, 'fleet.php', 3);
}

$slowestShipSpeed = min(
    array_map_withkeys($shipsDetails, function ($shipDetails) {
        return $shipDetails['speed'];
    })
);

// Speed modifier
if (MORALE_ENABLED) {
    if ($_User['morale_level'] <= MORALE_PENALTY_FLEETSLOWDOWN) {
        $slowestShipSpeed *= MORALE_PENALTY_FLEETSLOWDOWN_VALUE;
    }
}

$_Lang['P_HideACSJoining'] = $Hide;
$GetACSData = intval($_POST['getacsdata']);
$SetPosNotEmpty = false;
if($GetACSData > 0)
{
    $ACSData = doquery("SELECT `id`, `name`, `end_galaxy`, `end_system`, `end_planet`, `end_type`, `start_time` FROM {{table}} WHERE `id` = {$GetACSData};", 'acs', true);
    if($ACSData['id'] == $GetACSData)
    {
        if($ACSData['start_time'] > $Now)
        {
            $SetPos['g'] = $ACSData['end_galaxy'];
            $SetPos['s'] = $ACSData['end_system'];
            $SetPos['p'] = $ACSData['end_planet'];
            $SetPos['t'] = $ACSData['end_type'];

            $SetPosNotEmpty = true;
            $_Lang['P_HideACSJoining'] = '';
            $_Lang['fl1_ACSJoiningFleet'] = sprintf($_Lang['fl1_ACSJoiningFleet'], $ACSData['name'], $ACSData['end_galaxy'], $ACSData['end_system'], $ACSData['end_planet']);
            $_Lang['P_DisableCoordSel'] = 'disabled';
            $_Lang['SelectedACSID'] = $GetACSData;
        }
        else
        {
            message($_Lang['fl1_ACSTimeUp'], $ErrorTitle, 'fleet.php', 3);
        }
    }
    else
    {
        message($_Lang['fl1_ACSNoExist'], $ErrorTitle, 'fleet.php', 3);
    }
}

if($SetPosNotEmpty !== true)
{
    $SetPos['g'] = intval($_POST['galaxy']);
    $SetPos['s'] = intval($_POST['system']);
    $SetPos['p'] = intval($_POST['planet']);
    $SetPos['t'] = (isset($_POST['planet_type']) ? intval($_POST['planet_type']) : 0);
    if(!in_array($SetPos['t'], array(1, 2, 3)) && isset($_POST['planettype']))
    {
        $SetPos['t'] = intval($_POST['planettype']);
    }

    if($SetPos['g'] < 1 OR $SetPos['g'] > MAX_GALAXY_IN_WORLD)
    {
        $SetPos['g'] = $_Planet['galaxy'];
    }
    if($SetPos['s'] < 1 OR $SetPos['s'] > MAX_SYSTEM_IN_GALAXY)
    {
        $SetPos['s'] = $_Planet['system'];
    }
    if($SetPos['p'] < 1 OR $SetPos['p'] > (MAX_PLANET_IN_SYSTEM + 1))
    {
        $SetPos['p'] = $_Planet['planet'];
    }
    if(!in_array($SetPos['t'], array(1, 2, 3)))
    {
        $SetPos['t'] = $_Planet['planet_type'];
    }

    $_Lang['SetTargetMission'] = $_POST['target_mission'];
}
else
{
    $_Lang['SetTargetMission'] = 2;
}

// Show info boxes
$_Lang['P_SFBInfobox'] = FlightControl\Components\SmartFleetBlockadeInfoBox\render()['componentHTML'];

$_Lang['P_ShipsDetailsJSON'] = json_encode($shipsDetails, JSON_FORCE_OBJECT);
$_Lang['speedallsmin'] = $slowestShipSpeed;
$_Lang['MaxSpeedPretty'] = prettyNumber($slowestShipSpeed);
$_Lang['Storage'] = (string)($Fleet['storage'] + 0);
$_Lang['FuelStorage'] = (string)($Fleet['FuelStorage'] + 0);
$_Lang['ThisGalaxy'] = $_Planet['galaxy'];
$_Lang['ThisSystem'] = $_Planet['system'];
$_Lang['ThisPlanet'] = $_Planet['planet'];
$_Lang['GalaxyEnd'] = intval($_POST['galaxy']);
$_Lang['SystemEnd'] = intval($_POST['system']);
$_Lang['PlanetEnd'] = intval($_POST['planet']);
$_Lang['SpeedFactor'] = getUniFleetsSpeedFactor();
$_Lang['ThisPlanetType'] = $_Planet['planet_type'];
$_Lang['ThisResource3'] = (string)(floor($_Planet['deuterium']) + 0);
$_Lang['FleetArray'] = Array2String($Fleet['array']);
$_Lang['P_SetQuickRes'] = (
    ($_POST['quickres'] == '1') ? '1' : '0'
);

$_Lang['P_MaxGalaxy'] = MAX_GALAXY_IN_WORLD;
$_Lang['P_MaxSystem'] = MAX_SYSTEM_IN_GALAXY;
$_Lang['P_MaxPlanet'] = MAX_PLANET_IN_SYSTEM + 1;

foreach($SetPos as $Key => $Value)
{
    if($Key == 't')
    {
        $_Lang['SetPos_Type'.$Value.'Selected'] = 'selected';
        continue;
    }
    $_Lang['SetPos_'.$Key] = $Value;
}

$SpeedsAvailable = FlightControl\Utils\Helpers\getAvailableSpeeds([
    'user' => &$_User,
    'timestamp' => $Now,
]);

if (
    empty($_Set_DefaultSpeed) OR
    !in_array($_Set_DefaultSpeed, $SpeedsAvailable)
) {
    $_Set_DefaultSpeed = max($SpeedsAvailable);
}
$_Lang['Insert_SpeedInput'] = $_Set_DefaultSpeed;

foreach ($SpeedsAvailable as $Selector) {
    $Text = $Selector * 10;
    $isSpeedSelected = ($_Set_DefaultSpeed == $Selector);

    $_Lang['Insert_Speeds'][] = "<a href=\"#\" class=\"setSpeed ".($isSpeedSelected ? 'setSpeed_Selected setSpeed_Current' : '')."\" data-speed=\"{$Selector}\">{$Text}</a>";
}
$_Lang['Insert_Speeds'] = implode('<span class="speedBreak">|</span>', $_Lang['Insert_Speeds']);

// Create Colony List and Shortcuts List (dropdown)
$OtherPlanets = SortUserPlanets($_User);
$OtherPlanetsList = mapQueryResults($OtherPlanets, function ($resultEntry) use (&$_Planet) {
    if (
        $resultEntry['galaxy'] == $_Planet['galaxy'] &&
        $resultEntry['system'] == $_Planet['system'] &&
        $resultEntry['planet'] == $_Planet['planet'] &&
        $resultEntry['planet_type'] == $_Planet['planet_type']
    ) {
        return null;
    }

    return $resultEntry;
});
$OtherPlanetsList = Collections\compact($OtherPlanetsList);

$Shortcuts = FlightControl\Utils\Fetchers\fetchSavedShortcuts([ 'userId' => $_User['id'] ]);
$ShortcutList = mapQueryResults($Shortcuts, function ($resultEntry) {
    return $resultEntry;
});

$_Lang['P_HideFastLinks'] = $Hide;
$_Lang['P_HideNoFastLinks'] = $Hide;

if (
    !empty($OtherPlanetsList) ||
    !empty($ShortcutList)
) {
    $_Lang['P_HideFastLinks'] = '';

    $_Lang['FastLinks_Planets'] = FlightControl\Components\TargetsSelector\render([
        'targets' => $OtherPlanetsList,
        'selectorId' => 'fl_sel1',
        'isDisabled' => isset($_Lang['P_DisableCoordSel']),
    ])['componentHTML'];
    $_Lang['FastLinks_ShortCuts'] = FlightControl\Components\TargetsSelector\render([
        'targets' => $ShortcutList,
        'selectorId' => 'fl_sel2',
        'isDisabled' => isset($_Lang['P_DisableCoordSel']),
    ])['componentHTML'];

    if (empty($_Lang['FastLinks_Planets'])) {
        $_Lang['FastLinks_Planets'] = $_Lang['fl_no_planets'];
    }
    if (empty($_Lang['FastLinks_ShortCuts'])) {
        $_Lang['FastLinks_ShortCuts'] = $_Lang['fl_no_shortcuts'];
    }
} else {
    $_Lang['P_HideNoFastLinks'] = '';
}

$Page = parsetemplate(gettemplate('fleet1_body'), $_Lang);
display($Page, $_Lang['fl_title']);

?>
