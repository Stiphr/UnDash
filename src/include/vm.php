<?php
/*
 * UnDash - virtual machines through libvirt's virsh, the same backend Unraid's VMs tab uses.
 * Live status is one `virsh list --all`; the details (vCPUs, memory, icon, VNC port, guest agent) are cached and re-read
 * only when the set of VMs or a VM's state changes, or after 10 minutes.
 * Hibernate is "dompmsuspend <vm> disk", which needs the QEMU guest agent running inside the VM (as on the VMs tab).
 */


function und_valid_vm($n) { return is_string($n) && $n !== '' && strlen($n) <= 200 && !preg_match('/[\x00-\x1f\/]/', $n); }

/** name => state ("running", "shut off", "paused", "pmsuspended", "in shutdown", "crashed"). Null when libvirt is not running. */
function und_vm_states() {
  $l = und_api_vm_live();
  if (isset($l['error'])) return null;
  $o = [];
  foreach ($l['vms'] as $v) $o[$v['name']] = $v['state'];
  return $o;
}



function und_vm_live() { return und_api_vm_live(); }

function und_vm_action($name, $op) {
  if (!und_valid_vm($name)) return ['error' => 'Invalid VM name.'];
  return und_api_vm_action($name, $op);
}

function und_vm_summary() {
  $l = und_vm_live();
  if (isset($l['error'])) return $l;
  $s = ['running' => 0, 'stopped' => 0, 'paused' => 0, 'total' => count($l['vms'])];
  foreach ($l['vms'] as $v) {
    if ($v['state'] === 'running') $s['running']++; elseif (in_array($v['state'], ['paused', 'pmsuspended'], true)) $s['paused']++; else $s['stopped']++;
  }
  return $s;
}
