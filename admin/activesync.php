<?php
/**
 * Administrative management of ActiveSync devices.
 *
 * Copyright 1999-2010 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author   Michael J. Rubinsky <mrubinsk@horde.org>
 * @category Horde
 * @license  http://www.fsf.org/copyleft/lgpl.html LGPL
 * @package  Horde
 */

require_once dirname(__FILE__) . '/../lib/Application.php';
Horde_Registry::appInit('horde', array('admin' => true));

if (empty($conf['activesync']['enabled'])) {
    throw new Horde_Exception_PermissionDenied(_("ActiveSync not activated."));
}

$state_params = array_merge($conf['activesync']['state']['params'], array(
    'db' => $injector->getInstance('Horde_Db_Adapter_Base')
));
$stateMachine = new Horde_ActiveSync_State_History($state_params);
$stateMachine->setLogger($injector->getInstance('Horde_Log_Logger'));

/** Check for any actions **/
if ($actionID = Horde_Util::getPost('actionID')) {
    $deviceID = Horde_Util::getPost('deviceID');
    switch ($actionID) {
    case 'wipe':
        $stateMachine->setDeviceRWStatus($deviceID, Horde_ActiveSync::RWSTATUS_PENDING);
        $GLOBALS['notification']->push(_("A device wipe has been requested. Device will be wiped on next syncronization attempt."), 'horde.success');
        break;

    case 'cancelwipe':
        $stateMachine->setDeviceRWStatus($deviceID, Horde_ActiveSync::RWSTATUS_OK);
        $GLOBALS['notification']->push(_("Device wipe successfully canceled."), 'horde.success');
        break;

    case 'delete':
        $stateMachine->removeState(null, $deviceID, Horde_Util::getPost('uid'));
        $GLOBALS['notification']->push(_("Device successfully removed."), 'horde.success');
        break;

    case 'reset':
        $stateMachine->resetAllPolicyKeys();
        $GLOBALS['notification']->push(_("All policy keys successfully reset."), 'horde.success');
        break;
    }

    Horde::selfUrl()->redirect();
}

$devices = $stateMachine->listDevices();
$js = array();
foreach ($devices as $key => $val) {
    $js[$key] = array(
        'id' => $val['device_id'],
        'user' => $val['device_user']
    );
}

Horde::addScriptFile('activesyncadmin.js');
Horde::addInlineScript(array(
    'HordeActiveSyncAdmin.devices = ' . Horde_Serialize::serialize($js, Horde_Serialize::JSON, $registry->getCharset())
));

$title = _("ActiveSync Device Administration");
require HORDE_TEMPLATES . '/common-header.inc';
require HORDE_TEMPLATES . '/admin/menu.inc';

?>
<form id="activesyncadmin" name="activesyncadmin" action="<?php echo Horde::selfUrl()?>" method="post">
<input type="hidden" name="actionID" id="actionID" />
<input type="hidden" name="deviceID" id="deviceID" />
<input type="hidden" name="uid" id="uid" />
<?php

$spacer = '&nbsp;&nbsp;&nbsp;&nbsp;';
$icondir = array('icondir' => Horde_Themes::img());
$base_node_params = $icondir + array('icon' => 'administration.png');
$device_node = $icondir + array('icon' => 'mobile.png');
$user_node = $icondir + array('icon' => 'user.png');
$users = array();

$tree = $injector->getInstance('Horde_Tree')->getTree('admin_devices', 'Javascript');
$tree->setOption(array('alternate' => true));
$tree->setHeader(array(
                   array('width' => '30%'),
                   array('width' => '22%', 'html' => _("Last Sync Time")),
                   array('html' => $spacer),
                   array('width' => '6%', 'html' => _("Policy Key")),
                   array('html' => $spacer),
                   array('width' => '10%', 'html' => _("Status")),
                   array('html' => $spacer),
                   array('width' => '12%' , 'html' => _("Device ID")),
                   array('html' => $spacer),
                   array('width' => '10%', 'html' => _("Actions"))
 ));

/* Root tree node, and reprovision button */
$tree->addNode('root',
               null,
               _("Registered User Devices"),
               0,
               true,
               $base_node_params,
               array('--', $spacer, '--', $spacer, '--', $spacer, '--', $spacer, '<input class="button" type="button" value="' . _("Reprovision All Devices") . '" id="reset" />' ));

/* Build the device entry */
foreach ($devices as $key => $device) {
    $node_params = array();
    if (array_search($device['device_user'], $users) === false) {
        $users[] = $device['device_user'];
        $tree->addNode($device['device_user'], 'root', $device['device_user'], 0, false, $user_node);
    }

    /* Load this device */
    $stateMachine->loadDeviceInfo($device['device_id'], $device['device_user']);

    /* Parse the status */
    switch ($device['device_rwstatus']) {
    case Horde_ActiveSync::RWSTATUS_PENDING:
        $status = '<span class="notice">' . _("Wipe is pending") . '</span>';
        $device['ispending'] = true;
        break;

    case Horde_ActiveSync::RWSTATUS_WIPED:
        $status = '<span class="notice">' . _("Device is wiped") . '</span>';
        break;

    default:
        $status = $device['device_policykey'] ?_("Provisioned") : _("Not Provisioned");
        break;
    }

    /* Last sync time */
    $ts = new Horde_Date($stateMachine->getLastSyncTimestamp($device['device_id']));

    /* Build the action links */
    $actions = '';
    if ($device['device_policykey']) {
        $actions .= '<input class="button" type="button" value="' . _("Wipe") . '" id="wipe_' . $key . '" . />';
    } elseif ($device['device_rwstatus'] == Horde_ActiveSync::RWSTATUS_PENDING) {
        $actions .= '<input class="button" type="button" value="' . _("Cancel Wipe") . '" id="cancel_' . $key . '" />';
    }
    $actions .= '&nbsp;<input class="button removeDevice" type="button" value="' . _("Remove") . '" id="remove_' . $key . '" />';

    /* Add it */
    $tree->addNode($device['device_id'],
                   $device['device_user'],
                   $device['device_type']. ' | ' . $device['device_agent'],
                   0,
                   true,
                   $device_node + $node_params,
                   array($ts->format('r'), $spacer, $device['device_policykey'], $spacer, $status, $spacer, $device['device_id'], $spacer, $actions));
}

echo '<h1 class="header">' . Horde::img('group.png') . ' ' . _("ActiveSync Devices") . '</h1>';
$tree->renderTree();
echo '</form>';
require HORDE_TEMPLATES . '/common-footer.inc';
