<?php
/**
 * Users and roles family: user-list, user-get, user-create, user-update,
 * user-delete, role-list, role-create, role-delete, role-add-cap,
 * role-remove-cap, app-password-list, app-password-create,
 * app-password-delete.
 *
 * Password hashes never leave the site. A created password or application
 * password is returned exactly once, in the response of the call that made
 * it, because that is the only moment it exists in clear text.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class User_Tools {
	public static function describe( \WP_User $u ): array {
		return array(
			'id'           => (int) $u->ID,
			'login'        => (string) $u->user_login,
			'email'        => (string) $u->user_email,
			'display_name' => (string) $u->display_name,
			'roles'        => array_values( (array) $u->roles ),
			'registered'   => (string) $u->user_registered,
			'url'          => (string) $u->user_url,
		);
	}
	public static function find( $ref ): ?\WP_User {
		if ( is_int( $ref ) || ctype_digit( (string) $ref ) ) {
			$u = get_user_by( 'id', (int) $ref );
			return $u ? $u : null;
		}
		$u = get_user_by( 'login', (string) $ref );
		if ( ! $u ) {
			$u = get_user_by( 'email', (string) $ref );
		}
		return $u ? $u : null;
	}
}

final class User_List extends Base {
	public static function name(): string {
		return 'godmode/user-list';
	}
	public static function definition(): array {
		return self::make( 'users', 'List Users', 'List users with roles, filtered by role or search, paged.', self::obj( array( 'role' => self::str( 50 ), 'search' => self::str( 100 ), 'limit' => self::int( 1, 500, 100 ), 'offset' => self::int( 0, 0, 0 ) ) ), self::obj( array( 'users' => self::list_of( self::map() ), 'count' => array( 'type' => 'integer' ), 'total' => array( 'type' => 'integer' ) ), array( 'users', 'count', 'total' ) ), false );
	}
	public function execute( $input ) {
		$args = array( 'number' => (int) ( $input['limit'] ?? 100 ), 'offset' => (int) ( $input['offset'] ?? 0 ), 'orderby' => 'ID', 'count_total' => true );
		if ( ! empty( $input['role'] ) ) {
			$args['role'] = (string) $input['role'];
		}
		if ( ! empty( $input['search'] ) ) {
			$args['search']         = '*' . (string) $input['search'] . '*';
			$args['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}
		$q   = new \WP_User_Query( $args );
		$out = array_map( array( User_Tools::class, 'describe' ), (array) $q->get_results() );
		return array( 'users' => $out, 'count' => count( $out ), 'total' => (int) $q->get_total() );
	}
}

final class User_Get extends Base {
	public static function name(): string {
		return 'godmode/user-get';
	}
	public static function definition(): array {
		return self::make( 'users', 'Get User', 'Full profile of one user by id, login or email, including capabilities and public meta keys.', self::obj( array( 'user' => self::str( 200, 1 ) ), array( 'user' ) ), self::obj( array( 'user' => self::map(), 'capabilities' => self::list_of( self::str() ), 'meta_keys' => self::list_of( self::str() ) ), array( 'user', 'capabilities', 'meta_keys' ) ), false );
	}
	public function execute( $input ) {
		$u = User_Tools::find( $input['user'] );
		if ( ! $u ) {
			return self::err( 'godmode_not_found', 'No user matches "' . $input['user'] . '".', 404 );
		}
		$caps = array_keys( array_filter( (array) $u->allcaps ) );
		sort( $caps );
		$meta = array_keys( (array) get_user_meta( $u->ID ) );
		$meta = array_values( array_filter( $meta, static function ( $k ) { return ! \AIGodmode\Redactor::is_denied_key( (string) $k ) && 'session_tokens' !== $k; } ) );
		return array( 'user' => User_Tools::describe( $u ), 'capabilities' => $caps, 'meta_keys' => $meta );
	}
}

final class User_Create extends Base {
	public static function name(): string {
		return 'godmode/user-create';
	}
	public static function definition(): array {
		return self::make( 'users', 'Create User', 'Create a user. If password is omitted a strong one is generated and returned once. Default role is subscriber.', self::obj( array( 'login' => self::str( 60, 1 ), 'email' => self::str( 100, 3 ), 'password' => self::str( 255 ), 'role' => self::str( 50 ), 'display_name' => self::str( 250 ), 'url' => self::str( 100 ) ) + self::ack(), array( 'login', 'email' ) ), self::obj( array( 'user' => self::map(), 'password' => self::nullable( self::str() ) ), array( 'user' ) ), true, false );
	}
	public function execute( $input ) {
		$generated = empty( $input['password'] );
		$password  = $generated ? wp_generate_password( 24, true ) : (string) $input['password'];
		$role      = (string) ( $input['role'] ?? 'subscriber' );
		if ( ! get_role( $role ) ) {
			return self::err( 'godmode_bad_role', 'Role "' . $role . '" does not exist.' );
		}
		$id = wp_insert_user( array(
			'user_login'   => (string) $input['login'],
			'user_email'   => (string) $input['email'],
			'user_pass'    => $password,
			'role'         => $role,
			'display_name' => (string) ( $input['display_name'] ?? '' ),
			'user_url'     => (string) ( $input['url'] ?? '' ),
		) );
		if ( is_wp_error( $id ) ) {
			return self::err( 'godmode_user_create_failed', $id->get_error_message() );
		}
		return array( 'user' => User_Tools::describe( get_user_by( 'id', $id ) ), 'password' => $generated ? $password : null );
	}
}

final class User_Update extends Base {
	public static function name(): string {
		return 'godmode/user-update';
	}
	public static function definition(): array {
		return self::make( 'users', 'Update User', 'Change email, display name, url, password, or replace the role set of one user. Refuses to strip the administrator role from the last administrator.', self::obj( array( 'user' => self::str( 200, 1 ), 'email' => self::str( 100 ), 'display_name' => self::str( 250 ), 'url' => self::str( 100 ), 'password' => self::str( 255 ), 'roles' => self::list_of( self::str( 50 ) ) ) + self::ack(), array( 'user' ) ), self::obj( array( 'user' => self::map(), 'changed' => self::list_of( self::str() ) ), array( 'user', 'changed' ) ), true );
	}
	public function execute( $input ) {
		$u = User_Tools::find( $input['user'] );
		if ( ! $u ) {
			return self::err( 'godmode_not_found', 'No user matches "' . $input['user'] . '".', 404 );
		}
		$args    = array( 'ID' => $u->ID );
		$changed = array();
		foreach ( array( 'email' => 'user_email', 'display_name' => 'display_name', 'url' => 'user_url', 'password' => 'user_pass' ) as $in => $field ) {
			if ( isset( $input[ $in ] ) && '' !== $input[ $in ] ) {
				$args[ $field ] = (string) $input[ $in ];
				$changed[]      = $in;
			}
		}
		if ( count( $args ) > 1 ) {
			$r = wp_update_user( $args );
			if ( is_wp_error( $r ) ) {
				return self::err( 'godmode_user_update_failed', $r->get_error_message() );
			}
		}
		if ( isset( $input['roles'] ) && is_array( $input['roles'] ) ) {
			$roles = array_values( array_unique( array_map( 'strval', $input['roles'] ) ) );
			foreach ( $roles as $r ) {
				if ( ! get_role( $r ) ) {
					return self::err( 'godmode_bad_role', 'Role "' . $r . '" does not exist.' );
				}
			}
			if ( in_array( 'administrator', (array) $u->roles, true ) && ! in_array( 'administrator', $roles, true ) ) {
				$admins = count_users();
				if ( (int) ( $admins['avail_roles']['administrator'] ?? 0 ) <= 1 ) {
					return self::err( 'godmode_last_admin', 'Refused: that is the last administrator.', 409 );
				}
			}
			$u->set_role( '' );
			foreach ( $roles as $r ) {
				$u->add_role( $r );
			}
			$changed[] = 'roles';
		}
		return array( 'user' => User_Tools::describe( get_user_by( 'id', $u->ID ) ), 'changed' => $changed );
	}
}

final class User_Delete extends Base {
	public static function name(): string {
		return 'godmode/user-delete';
	}
	public static function definition(): array {
		return self::make( 'users', 'Delete User', 'Delete a user, reassigning their content to another user id (or deleting it when reassign is omitted). Refuses the current user and the last administrator.', self::obj( array( 'user' => self::str( 200, 1 ), 'reassign' => self::int( 1 ) ) + self::ack(), array( 'user' ) ), self::obj( array( 'deleted' => self::bool(), 'id' => array( 'type' => 'integer' ), 'login' => self::str() ), array( 'deleted', 'id', 'login' ) ), true, false );
	}
	public function execute( $input ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$u = User_Tools::find( $input['user'] );
		if ( ! $u ) {
			return self::err( 'godmode_not_found', 'No user matches "' . $input['user'] . '".', 404 );
		}
		if ( (int) $u->ID === get_current_user_id() ) {
			return self::err( 'godmode_self_protect', 'Refused: that is the user running this ability.', 403 );
		}
		if ( in_array( 'administrator', (array) $u->roles, true ) ) {
			$admins = count_users();
			if ( (int) ( $admins['avail_roles']['administrator'] ?? 0 ) <= 1 ) {
				return self::err( 'godmode_last_admin', 'Refused: that is the last administrator.', 409 );
			}
		}
		$reassign = isset( $input['reassign'] ) ? (int) $input['reassign'] : null;
		if ( null !== $reassign && ! get_user_by( 'id', $reassign ) ) {
			return self::err( 'godmode_not_found', 'Reassign target user does not exist.', 404 );
		}
		$ok = wp_delete_user( $u->ID, $reassign );
		return array( 'deleted' => (bool) $ok, 'id' => (int) $u->ID, 'login' => (string) $u->user_login );
	}
}

final class Role_List extends Base {
	public static function name(): string {
		return 'godmode/role-list';
	}
	public static function definition(): array {
		return self::make( 'users', 'List Roles', 'Every role with its capability list and user count.', self::obj( array() ), self::obj( array( 'roles' => self::list_of( self::map() ) ), array( 'roles' ) ), false );
	}
	public function execute( $input ) {
		$counts = count_users();
		$out    = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = array_keys( array_filter( (array) ( $role['capabilities'] ?? array() ) ) );
			sort( $caps );
			$out[] = array( 'role' => $slug, 'name' => (string) $role['name'], 'capabilities' => $caps, 'users' => (int) ( $counts['avail_roles'][ $slug ] ?? 0 ) );
		}
		return array( 'roles' => $out );
	}
}

final class Role_Create extends Base {
	public static function name(): string {
		return 'godmode/role-create';
	}
	public static function definition(): array {
		return self::make( 'users', 'Create Role', 'Create a role with a capability list, optionally cloning another role first.', self::obj( array( 'role' => self::str( 50, 1 ), 'name' => self::str( 100, 1 ), 'clone_from' => self::str( 50 ), 'capabilities' => self::list_of( self::str( 100 ) ) ) + self::ack(), array( 'role', 'name' ) ), self::obj( array( 'role' => self::str(), 'capabilities' => self::list_of( self::str() ) ), array( 'role', 'capabilities' ) ), true, false );
	}
	public function execute( $input ) {
		$slug = sanitize_key( (string) $input['role'] );
		if ( get_role( $slug ) ) {
			return self::err( 'godmode_role_exists', 'Role "' . $slug . '" already exists.', 409 );
		}
		$caps = array();
		if ( ! empty( $input['clone_from'] ) ) {
			$src = get_role( (string) $input['clone_from'] );
			if ( ! $src ) {
				return self::err( 'godmode_bad_role', 'clone_from role does not exist.' );
			}
			$caps = (array) $src->capabilities;
		}
		foreach ( (array) ( $input['capabilities'] ?? array() ) as $c ) {
			$caps[ (string) $c ] = true;
		}
		$r = add_role( $slug, (string) $input['name'], $caps );
		if ( ! $r ) {
			return self::err( 'godmode_role_create_failed', 'add_role() returned null.', 500 );
		}
		return array( 'role' => $slug, 'capabilities' => array_keys( array_filter( $caps ) ) );
	}
}

final class Role_Delete extends Base {
	public static function name(): string {
		return 'godmode/role-delete';
	}
	public static function definition(): array {
		return self::make( 'users', 'Delete Role', 'Remove a role. Refuses built-in roles and roles that still have users.', self::obj( array( 'role' => self::str( 50, 1 ) ) + self::ack(), array( 'role' ) ), self::obj( array( 'role' => self::str(), 'deleted' => self::bool() ), array( 'role', 'deleted' ) ), true );
	}
	public function execute( $input ) {
		$slug = (string) $input['role'];
		if ( in_array( $slug, array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' ), true ) ) {
			return self::err( 'godmode_builtin_role', 'Built-in roles cannot be deleted.', 403 );
		}
		if ( ! get_role( $slug ) ) {
			return self::err( 'godmode_not_found', 'Role "' . $slug . '" does not exist.', 404 );
		}
		$counts = count_users();
		if ( (int) ( $counts['avail_roles'][ $slug ] ?? 0 ) > 0 ) {
			return self::err( 'godmode_role_in_use', 'Role still has users. Reassign them first.', 409 );
		}
		remove_role( $slug );
		return array( 'role' => $slug, 'deleted' => null === get_role( $slug ) );
	}
}

final class Role_Add_Cap extends Base {
	public static function name(): string {
		return 'godmode/role-add-cap';
	}
	public static function definition(): array {
		return self::make( 'users', 'Add Capability', 'Grant capabilities to a role, or to one user when user is given.', self::obj( array( 'capabilities' => self::list_of( self::str( 100, 1 ) ), 'role' => self::str( 50 ), 'user' => self::str( 200 ) ) + self::ack(), array( 'capabilities' ) ), self::obj( array( 'target' => self::str(), 'added' => self::list_of( self::str() ) ), array( 'target', 'added' ) ), true );
	}
	public function execute( $input ) {
		$caps = array_map( 'strval', (array) $input['capabilities'] );
		if ( ! empty( $input['user'] ) ) {
			$u = User_Tools::find( $input['user'] );
			if ( ! $u ) {
				return self::err( 'godmode_not_found', 'No user matches "' . $input['user'] . '".', 404 );
			}
			foreach ( $caps as $c ) {
				$u->add_cap( $c );
			}
			return array( 'target' => 'user:' . $u->user_login, 'added' => $caps );
		}
		$role = get_role( (string) ( $input['role'] ?? '' ) );
		if ( ! $role ) {
			return self::err( 'godmode_bad_role', 'Give a role that exists, or a user.' );
		}
		foreach ( $caps as $c ) {
			$role->add_cap( $c );
		}
		return array( 'target' => 'role:' . $role->name, 'added' => $caps );
	}
}

final class Role_Remove_Cap extends Base {
	public static function name(): string {
		return 'godmode/role-remove-cap';
	}
	public static function definition(): array {
		return self::make( 'users', 'Remove Capability', 'Revoke capabilities from a role, or from one user when user is given. Refuses to strip manage_options from the administrator role.', self::obj( array( 'capabilities' => self::list_of( self::str( 100, 1 ) ), 'role' => self::str( 50 ), 'user' => self::str( 200 ) ) + self::ack(), array( 'capabilities' ) ), self::obj( array( 'target' => self::str(), 'removed' => self::list_of( self::str() ) ), array( 'target', 'removed' ) ), true );
	}
	public function execute( $input ) {
		$caps = array_map( 'strval', (array) $input['capabilities'] );
		if ( ! empty( $input['user'] ) ) {
			$u = User_Tools::find( $input['user'] );
			if ( ! $u ) {
				return self::err( 'godmode_not_found', 'No user matches "' . $input['user'] . '".', 404 );
			}
			foreach ( $caps as $c ) {
				$u->remove_cap( $c );
			}
			return array( 'target' => 'user:' . $u->user_login, 'removed' => $caps );
		}
		$slug = (string) ( $input['role'] ?? '' );
		$role = get_role( $slug );
		if ( ! $role ) {
			return self::err( 'godmode_bad_role', 'Give a role that exists, or a user.' );
		}
		if ( 'administrator' === $slug && in_array( 'manage_options', $caps, true ) ) {
			return self::err( 'godmode_self_protect', 'Refused: removing manage_options from administrator would lock everyone out, Godmode included.', 403 );
		}
		foreach ( $caps as $c ) {
			$role->remove_cap( $c );
		}
		return array( 'target' => 'role:' . $slug, 'removed' => $caps );
	}
}

final class App_Password_List extends Base {
	public static function name(): string {
		return 'godmode/app-password-list';
	}
	public static function definition(): array {
		return self::make( 'users', 'List Application Passwords', 'Application passwords of one user: name, uuid, created, last used. Never the secret.', self::obj( array( 'user' => self::str( 200, 1 ) ), array( 'user' ) ), self::obj( array( 'user' => self::str(), 'passwords' => self::list_of( self::map() ) ), array( 'user', 'passwords' ) ), false );
	}
	public function execute( $input ) {
		$u = User_Tools::find( $input['user'] );
		if ( ! $u ) {
			return self::err( 'godmode_not_found', 'No user matches "' . $input['user'] . '".', 404 );
		}
		$out = array();
		foreach ( (array) \WP_Application_Passwords::get_user_application_passwords( $u->ID ) as $p ) {
			$out[] = array( 'uuid' => (string) $p['uuid'], 'name' => (string) $p['name'], 'created' => (int) $p['created'], 'last_used' => isset( $p['last_used'] ) ? (int) $p['last_used'] : null, 'last_ip' => (string) ( $p['last_ip'] ?? '' ) );
		}
		return array( 'user' => (string) $u->user_login, 'passwords' => $out );
	}
}

final class App_Password_Create extends Base {
	public static function name(): string {
		return 'godmode/app-password-create';
	}
	public static function definition(): array {
		return self::make( 'users', 'Create Application Password', 'Create an application password for a user. The password is returned exactly once, in this response, and is never stored in clear text anywhere.', self::obj( array( 'user' => self::str( 200, 1 ), 'name' => self::str( 100, 1 ) ) + self::ack(), array( 'user', 'name' ) ), self::obj( array( 'user' => self::str(), 'uuid' => self::str(), 'name' => self::str(), 'password' => self::str() ), array( 'user', 'uuid', 'name', 'password' ) ), true, false );
	}
	public function execute( $input ) {
		$u = User_Tools::find( $input['user'] );
		if ( ! $u ) {
			return self::err( 'godmode_not_found', 'No user matches "' . $input['user'] . '".', 404 );
		}
		$r = \WP_Application_Passwords::create_new_application_password( $u->ID, array( 'name' => (string) $input['name'] ) );
		if ( is_wp_error( $r ) ) {
			return self::err( 'godmode_app_password_failed', $r->get_error_message() );
		}
		list( $password, $item ) = $r;
		return array( 'user' => (string) $u->user_login, 'uuid' => (string) $item['uuid'], 'name' => (string) $item['name'], 'password' => \WP_Application_Passwords::chunk_password( $password ) );
	}
}

final class App_Password_Delete extends Base {
	public static function name(): string {
		return 'godmode/app-password-delete';
	}
	public static function definition(): array {
		return self::make( 'users', 'Delete Application Password', 'Revoke one application password by uuid, or all of a user\'s when uuid is "*".', self::obj( array( 'user' => self::str( 200, 1 ), 'uuid' => self::str( 40, 1 ) ) + self::ack(), array( 'user', 'uuid' ) ), self::obj( array( 'user' => self::str(), 'deleted' => self::bool() ), array( 'user', 'deleted' ) ), true );
	}
	public function execute( $input ) {
		$u = User_Tools::find( $input['user'] );
		if ( ! $u ) {
			return self::err( 'godmode_not_found', 'No user matches "' . $input['user'] . '".', 404 );
		}
		$uuid = (string) $input['uuid'];
		$r    = '*' === $uuid ? \WP_Application_Passwords::delete_all_application_passwords( $u->ID ) : \WP_Application_Passwords::delete_application_password( $u->ID, $uuid );
		if ( is_wp_error( $r ) ) {
			return self::err( 'godmode_app_password_failed', $r->get_error_message(), 404 );
		}
		return array( 'user' => (string) $u->user_login, 'deleted' => (bool) $r );
	}
}
