<?php

declare(strict_types=1);

return [
	'routes' => [
		// Navigation entry
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// External collaborator signup (public, no session)
		['name' => 'signup#showForm',   'url' => '/signup',         'verb' => 'GET'],
		['name' => 'signup#submitForm', 'url' => '/signup',         'verb' => 'POST'],
		['name' => 'signup#decline',    'url' => '/signup/decline', 'verb' => 'GET'],

		// Internal silo-sync endpoints (shared secret, no NC session)
		['name' => 'internal#syncGroup',    'url' => '/internal/groups/sync',                              'verb' => 'POST'],
		['name' => 'internal#deleteGroup',  'url' => '/internal/groups/{gid}/delete',                     'verb' => 'POST'],
		['name' => 'internal#syncMember',   'url' => '/internal/groups/{gid}/members/sync',               'verb' => 'POST'],
		['name' => 'internal#deleteMember', 'url' => '/internal/groups/{gid}/members/{uid}/delete',       'verb' => 'POST'],
		['name' => 'internal#searchUsers',  'url' => '/internal/users/search',                            'verb' => 'GET'],
	],
	'ocs' => [
		// Users
		['name' => 'group#searchUsers',   'url' => '/api/v1/users/search',     'verb' => 'GET'],
		['name' => 'group#listInvitations', 'url' => '/api/v1/invitations',    'verb' => 'GET'],

		// Groups
		['name' => 'group#listGroups',    'url' => '/api/v1/groups',           'verb' => 'GET'],
		['name' => 'group#searchJoinable','url' => '/api/v1/groups/search',    'verb' => 'GET'],
		['name' => 'group#createGroup',   'url' => '/api/v1/groups',           'verb' => 'POST'],
		['name' => 'group#getGroup',      'url' => '/api/v1/groups/{gid}',     'verb' => 'GET'],
		['name' => 'group#updateGroup',   'url' => '/api/v1/groups/{gid}',     'verb' => 'PUT'],
		['name' => 'group#deleteGroup',   'url' => '/api/v1/groups/{gid}',     'verb' => 'DELETE'],

		// Members
		['name' => 'group#listMembers',      'url' => '/api/v1/groups/{gid}/members',          'verb' => 'GET'],
		['name' => 'group#inviteOrRequest',  'url' => '/api/v1/groups/{gid}/members',          'verb' => 'POST'],
		['name' => 'group#inviteExternal',   'url' => '/api/v1/groups/{gid}/members/external', 'verb' => 'POST'],
		['name' => 'group#acceptMembership', 'url' => '/api/v1/groups/{gid}/members/{uid}',    'verb' => 'PUT'],
		['name' => 'group#removeMember',     'url' => '/api/v1/groups/{gid}/members/{uid}',    'verb' => 'DELETE'],
	],
];
