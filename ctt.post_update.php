<?php

/**
 * @file
 * Post-update hooks for the CTT module.
 */

/**
 * Grants new workflow matrix permissions to administrator role.
 */
function ctt_post_update_grant_workflow_matrix_permissions(&$sandbox = NULL) {
  $role = \Drupal\user\Entity\Role::load('administrator');
  if (!$role) {
    return 'Administrator role not found. No permission changes applied.';
  }

  $permissions = [
    'access ctt editor',
    'create ctt workflow',
    'edit ctt workflow',
    'submit ctt workflow',
    'administer ctt',
  ];

  $changed = FALSE;
  foreach ($permissions as $permission) {
    if (!$role->hasPermission($permission)) {
      $role->grantPermission($permission);
      $changed = TRUE;
    }
  }

  if ($changed) {
    $role->save();
    return 'Administrator role updated with CTT workflow matrix permissions.';
  }

  return 'Administrator role already has CTT workflow matrix permissions.';
}
