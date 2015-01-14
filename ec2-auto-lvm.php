<?php

require 'vendor/autoload.php';
use Aws\Ec2\Ec2Client;

function mapDeviceName( $amazonDev ) {
	return str_replace( "/dev/sd", "/dev/xvd", trim( $amazonDev ) );
}

/**
 * @return array
 */
function getDeviceMapFromEC2Tags() {
// @TODO: set timeout for this call, which will enable us to bail fast if run in non-ec2 envs.
	$ec2 = json_decode( file_get_contents( 'http://169.254.169.254/latest/dynamic/instance-identity/document' ) );


	$client = Ec2Client::factory( array(
		'region' => $ec2->region
	) );

	$instance = $client->describeInstances( array(
		'InstanceIds' => array( $ec2->instanceId )
	) );

	$deviceMap = array();
	$volumeIds = array();
	$lvmGroups = array();

	foreach ( $instance['Reservations'][0]['Instances'][0]['BlockDeviceMappings'] as $data ) {
		$volumeId   = @$data['Ebs']['VolumeId'];
		$deviceName = mapDeviceName( @$data['DeviceName'] );
		if ( ( $volumeId ) && ( $deviceName ) ) {
			if ( $deviceName == "/dev/xvda1" ) {
				continue;
			}
			$deviceMap[ $volumeId ] = array( 'device' => $deviceName, 'volumeId' => $volumeId );
			$volumeIds[]            = $volumeId;
		}
	}

	$volumeData = $client->describeVolumes( array(
		'VolumeIds' => $volumeIds,
		'Filters'   => array(
			array( 'Name' => 'tag-key', 'Values' => array( 'auto_lvm_group' ) ),
		)
	) );


	foreach ( $volumeData['Volumes'] as $volData ) {
		$volumeId       = $volData['VolumeId'];
		$auto_lvm_group = null;
		foreach ( $volData['Tags'] as $tag ) {
			if ( $tag['Key'] == "auto_lvm_group" ) {
				$auto_lvm_group = trim( $tag['Value'] );
				break;
			}
		}
		if ( $auto_lvm_group ) {
			$deviceMap[ $volumeId ]['lvm']  = $auto_lvm_group;
			$lvmGroups[ $auto_lvm_group ][] = $deviceMap[ $volumeId ];
		}
	}

	return array( $deviceMap, $lvmGroups );
}


function runLVMCommand( $lvmCommand, $params ) {
	$fullCommand = "$lvmCommand " . implode( " ", $params );
	echo $fullCommand . "\n";
	passthru( $fullCommand, $ret );
	if ( $ret == 0 ) {
		echo "OK.\n";
	} else {
		echo "ERROR: $ret\n";
	}
}

function parseCurrentPvDisplay() {
	$command = "/sbin/pvdisplay -c";
	$lines   = array();
	exec( $command, $lines, $ret );

	$full = array();
	foreach ( $lines as $line ) {
		if ( ( strpos( ( $line ), "is a new physical volume of" ) === false ) ) {
			$parse  = explode( ":", trim( $line ) );
			$full[] = array( 'device' => $parse[0], 'vg' => $parse[1] == "#orphans_lvm2" ? null : $parse[1] );
		}
	}

}


list( $deviceMap, $lvmGroups ) = getDeviceMapFromEC2Tags();

foreach ( $deviceMap as $device ) {
	# This should be idempotent, so we're free to run it everytime.
	runLVMCommand( "/sbin/pvcreate", array( $device['device'] ) );
}

//parseCurrentPvDisplay();


foreach ( $lvmGroups as $groupName => $devices ) {
	$vgName    = "$groupName";
	$lvName    = "$groupName";
	$labelName = "$groupName";

	$devs = array();
	foreach ( $devices as $device ) {
		$devs[] = $device['device'];
	}

	$params = array( $vgName );
	$params = array_merge( $params, $devs );
	runLVMCommand( "/sbin/vgcreate", $params );
	runLVMCommand( "/sbin/lvcreate -l 100%FREE -n $lvName $vgName", array() );
	runLVMCommand( "/sbin/mkfs.ext4 /dev/$vgName/$lvName -L $labelName -m 0", array() );
}
