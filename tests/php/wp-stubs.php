<?php
/* Broad WordPress stub layer for exercising plugin PHP without a WP runtime. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'KB_IN_BYTES', 1024 );
define( 'MB_IN_BYTES', 1048576 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'WP_CONTENT_DIR', dirname( __DIR__ ) . '/.out/wp-content' );
if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $s, $a, $b = null ) { return null === $b ? substr( $s, $a ) : substr( $s, $a, $b ); }
	function mb_strlen( $s ) { return strlen( $s ); }
}

$GLOBALS['posts'] = array(); $GLOBALS['meta'] = array(); $GLOBALS['terms'] = array(); $GLOBALS['opts'] = array();
$GLOBALS['transients'] = array(); $GLOBALS['hooks'] = array(); $GLOBALS['users'] = array(); $GLOBALS['umeta'] = array();
$GLOBALS['caps'] = array(); $GLOBALS['current_user'] = 0; $GLOBALS['mail'] = array(); $GLOBALS['http'] = array();
$GLOBALS['rest_routes'] = array(); $GLOBALS['cron'] = array(); $GLOBALS['redirect'] = null; $GLOBALS['next_id'] = 1000;
$GLOBALS['http_handler'] = null; $GLOBALS['cache'] = array(); $GLOBALS['fails'] = 0;

class WP_Error {
	public $errors = array(); public $error_data = array();
	public function __construct( $code = '', $msg = '', $data = null ) { if ( $code ) { $this->errors[ $code ][] = $msg; if ( null !== $data ) { $this->error_data[ $code ] = $data; } } }
	public function get_error_code() { $k = array_keys( $this->errors ); return $k ? $k[0] : ''; }
	public function get_error_message( $c = '' ) { $c = $c ?: $this->get_error_code(); return $c ? $this->errors[ $c ][0] : ''; }
	public function get_error_data( $c = '' ) { $c = $c ?: $this->get_error_code(); return $this->error_data[ $c ] ?? null; }
	public function add_data( $d, $c = '' ) { $c = $c ?: $this->get_error_code(); $this->error_data[ $c ] = $d; }
	public function add( $c, $m, $d = null ) { $this->errors[ $c ][] = $m; if ( null !== $d ) { $this->error_data[ $c ] = $d; } }
	public function has_errors() { return ! empty( $this->errors ); }
}
#[AllowDynamicProperties]
class WP_Post { public function __construct( $a ) { foreach ( $a as $k => $v ) { $this->$k = $v; } } }
class WP_User {
	public $ID; public $user_login; public $user_email; public $display_name; public $roles = array();
	public function __construct( $id = 0 ) { $this->ID = (int) $id; $u = $GLOBALS['users'][ $id ] ?? array(); $this->user_login = $u['login'] ?? ''; $this->user_email = $u['email'] ?? ''; $this->display_name = $u['name'] ?? ''; $this->roles = $u['roles'] ?? array(); }
	public function exists() { return $this->ID > 0; }
	public function has_cap( $c ) { return user_can( $this->ID, $c ); }
	public function add_cap( $c ) { $GLOBALS['caps'][ $this->ID ][ $c ] = true; }
	public function remove_cap( $c ) { unset( $GLOBALS['caps'][ $this->ID ][ $c ] ); }
	public function add_role( $r ) { $this->roles[] = $r; $GLOBALS['users'][ $this->ID ]['roles'] = $this->roles; }
	public function remove_role( $r ) { $this->roles = array_values( array_diff( $this->roles, array( $r ) ) ); $GLOBALS['users'][ $this->ID ]['roles'] = $this->roles; }
	public function set_role( $r ) { $this->roles = array( $r ); $GLOBALS['users'][ $this->ID ]['roles'] = $this->roles; }
}
class WP_Role {
	public $name; public $capabilities = array();
	public function __construct( $n, $c = array() ) { $this->name = $n; $this->capabilities = $c; }
	public function add_cap( $c, $g = true ) { $this->capabilities[ $c ] = $g; }
	public function remove_cap( $c ) { unset( $this->capabilities[ $c ] ); }
	public function has_cap( $c ) { return ! empty( $this->capabilities[ $c ] ); }
}
class WP_Roles {
	public $roles = array(); public $role_objects = array(); public $role_names = array();
	public function __construct() { foreach ( array( 'administrator', 'editor', 'subscriber' ) as $r ) { $this->add_role( $r, ucfirst( $r ), 'administrator' === $r ? array( 'manage_options' => true, 'edit_posts' => true, 'upload_files' => true ) : array( 'read' => true ) ); } }
	public function add_role( $r, $n, $c = array() ) { $this->role_objects[ $r ] = new WP_Role( $r, $c ); $this->role_names[ $r ] = $n; $this->roles[ $r ] = array( 'name' => $n, 'capabilities' => $c ); return $this->role_objects[ $r ]; }
	public function get_role( $r ) { return $this->role_objects[ $r ] ?? null; }
	public function remove_role( $r ) { unset( $this->role_objects[ $r ], $this->role_names[ $r ], $this->roles[ $r ] ); }
	public function is_role( $r ) { return isset( $this->role_objects[ $r ] ); }
}
$GLOBALS['wp_roles'] = new WP_Roles();
function wp_roles() { return $GLOBALS['wp_roles']; }
function add_role( $r, $n, $c = array() ) { return wp_roles()->add_role( $r, $n, $c ); }
function get_role( $r ) { return wp_roles()->get_role( $r ); }
function remove_role( $r ) { wp_roles()->remove_role( $r ); }
function translate_user_role( $n ) { return $n; }

class WP_REST_Request implements ArrayAccess {
	private $params = array(); private $headers = array(); private $method; private $route; private $files = array(); private $body = '';
	public function __construct( $method = 'GET', $route = '', $params = array(), $headers = array() ) { $this->method = $method; $this->route = $route; $this->params = $params; foreach ( $headers as $k => $v ) { $this->headers[ strtolower( str_replace( '-', '_', $k ) ) ] = (array) $v; } }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function get_params() { return $this->params; }
	public function get_json_params() { return $this->params; }
	public function get_body_params() { return $this->params; }
	public function get_query_params() { return $this->params; }
	public function get_url_params() { return $this->params; }
	public function set_param( $k, $v ) { $this->params[ $k ] = $v; }
	public function get_header( $k ) { $k = strtolower( str_replace( '-', '_', $k ) ); return isset( $this->headers[ $k ] ) ? implode( ',', $this->headers[ $k ] ) : null; }
	public function set_header( $k, $v ) { $this->headers[ strtolower( str_replace( '-', '_', $k ) ) ] = (array) $v; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function get_file_params() { return $this->files; }
	public function set_file_params( $f ) { $this->files = $f; }
	public function get_body() { return $this->body; }
	public function offsetExists( $k ): bool { return isset( $this->params[ $k ] ); }
	public function offsetGet( $k ): mixed { return $this->params[ $k ] ?? null; }
	public function offsetSet( $k, $v ): void { $this->params[ $k ] = $v; }
	public function offsetUnset( $k ): void { unset( $this->params[ $k ] ); }
}
class WP_REST_Response {
	public $data; public $status; public $headers = array();
	public function __construct( $d = null, $s = 200, $h = array() ) { $this->data = $d; $this->status = $s; $this->headers = $h; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
	public function set_status( $s ) { $this->status = $s; }
	public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST, PUT, PATCH'; const DELETABLE = 'DELETE'; }
function rest_ensure_response( $r ) { if ( is_wp_error( $r ) || $r instanceof WP_REST_Response ) { return $r; } return new WP_REST_Response( $r ); }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['rest_routes'][ $ns . $route ] = $args; return true; }
function rest_url( $p = '' ) { return 'http://example.test/wp-json/' . ltrim( $p, '/' ); }

/* dispatch helper: emulates permission + callback */
function pct_rest( $method, $route, $params = array(), $headers = array(), $files = array() ) {
	$full = 'pct/v1' . $route;
	$def  = null; $pat = '';
	foreach ( $GLOBALS['rest_routes'] as $pattern => $args ) {
		if ( preg_match( '#^' . $pattern . '$#', $full ) ) { $def = $args; $pat = $pattern; break; }
	}
	if ( ! $def ) { return new WP_Error( 'rest_no_route', 'No route ' . $full, array( 'status' => 404 ) ); }
	if ( preg_match( '#^' . $pat . '$#', $full, $m ) ) { foreach ( $m as $k => $v ) { if ( is_string( $k ) ) { $params[ $k ] = $v; } } }
	$handlers = isset( $def['callback'] ) ? array( $def ) : $def;
	foreach ( $handlers as $h ) {
		$methods = array_map( 'trim', explode( ',', $h['methods'] ) );
		if ( ! in_array( $method, $methods, true ) ) { continue; }
		$req = new WP_REST_Request( $method, '/' . $full, $params, $headers );
		$req->set_file_params( $files );
		PCT_Rest::apply_language( null, null, $req );
		if ( isset( $h['args'] ) ) { foreach ( $h['args'] as $k => $a ) { if ( ! empty( $a['required'] ) && ! isset( $params[ $k ] ) ) { return new WP_Error( 'rest_missing_callback_param', 'Missing ' . $k, array( 'status' => 400 ) ); } } }
		$perm = call_user_func( $h['permission_callback'], $req );
		if ( is_wp_error( $perm ) ) { return $perm; }
		if ( ! $perm ) { return new WP_Error( 'rest_forbidden', 'forbidden', array( 'status' => 403 ) ); }
		return call_user_func( $h['callback'], $req );
	}
	return new WP_Error( 'rest_no_method', 'No method ' . $method, array( 'status' => 405 ) );
}
function pct_data( $r ) { return $r instanceof WP_REST_Response ? $r->get_data() : $r; }
function __return_true() { return true; }
function __return_false() { return false; }
function __return_empty_array() { return array(); }

/* hooks */
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][ $p ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][ $p ][] = $cb; }
function has_action( $h ) { return ! empty( $GLOBALS['hooks'][ $h ] ); }
function remove_action() {} function remove_filter() {}
function do_action( $h, ...$args ) { if ( empty( $GLOBALS['hooks'][ $h ] ) ) { return; } ksort( $GLOBALS['hooks'][ $h ] ); foreach ( $GLOBALS['hooks'][ $h ] as $cbs ) { foreach ( $cbs as $cb ) { call_user_func_array( $cb, $args ); } } }
function apply_filters( $h, $v, ...$args ) { if ( empty( $GLOBALS['hooks'][ $h ] ) ) { return $v; } ksort( $GLOBALS['hooks'][ $h ] ); foreach ( $GLOBALS['hooks'][ $h ] as $cbs ) { foreach ( $cbs as $cb ) { $v = call_user_func_array( $cb, array_merge( array( $v ), $args ) ); } } return $v; }
function add_shortcode( $t, $cb ) { $GLOBALS['shortcodes'][ $t ] = $cb; }
function has_shortcode( $c, $t ) { return false !== strpos( (string) $c, '[' . $t ); }
function shortcode_atts( $d, $a ) { return array_merge( $d, array_intersect_key( (array) $a, $d ) ); }
function register_activation_hook( $f, $cb ) { $GLOBALS['activation'] = $cb; }
function register_deactivation_hook( $f, $cb ) { $GLOBALS['deactivation'] = $cb; }
function load_plugin_textdomain() { return true; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'http://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }
function flush_rewrite_rules() {}
function register_post_type( $t, $a ) { $GLOBALS['post_types'][ $t ] = $a; }
function register_taxonomy( $t, $o, $a ) { $GLOBALS['taxonomies'][ $t ] = $a; }
function taxonomy_exists( $t ) { return isset( $GLOBALS['taxonomies'][ $t ] ); }
function register_setting() {}
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . $g . '">'; }
function settings_errors() {}
function submit_button( $t = 'Save' ) { echo '<p><button class="button button-primary">' . $t . '</button></p>'; }
function add_menu_page() { return 'pct'; } function add_submenu_page() { return 'pct-sub'; } function add_meta_box() {}
function get_current_screen() { return (object) array( 'id' => 'toplevel_page_pct', 'post_type' => '', 'base' => 'toplevel_page_pct' ); }
function is_admin() { return ! empty( $GLOBALS['is_admin'] ); }
function wp_doing_ajax() { return false; }
function wp_enqueue_script() {} function wp_enqueue_style() {} function wp_register_script() {} function wp_register_style() {}
function wp_localize_script( $h, $n, $d ) { $GLOBALS['localized'][ $n ] = $d; }
function wp_style_is() { return false; }

/* i18n + escaping */
function __( $s, $d = 'default' ) { return apply_filters( 'gettext_' . $d, $s, $s, $d ); }
function _x( $s, $c, $d = 'default' ) { return apply_filters( 'gettext_with_context_' . $d, $s, $s, $c, $d ); }
function _n( $a, $b, $n, $d = 'default' ) { return apply_filters( 'ngettext_' . $d, 1 == $n ? $a : $b, $a, $b, $n, $d ); }
function esc_html__( $s, $d = 'default' ) { return __( $s, $d ); } function esc_attr__( $s, $d = 'default' ) { return __( $s, $d ); }
function esc_html_e( $s, $d = 'default' ) { echo __( $s, $d ); } function esc_attr_e( $s, $d = 'default' ) { echo __( $s, $d ); } function _e( $s, $d = 'default' ) { echo __( $s, $d ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; } function esc_url_raw( $s ) { return (string) $s; }
function wp_kses_post( $s ) { return $s; } function wp_kses( $s, $a ) { return $s; }
function wp_specialchars_decode( $s ) { return htmlspecialchars_decode( (string) $s ); }
function checked( $a, $b = true, $e = true ) { $o = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $e ) { echo $o; } return $o; }
function selected( $a, $b = true, $e = true ) { $o = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $e ) { echo $o; } return $o; }
function disabled( $a, $b = true, $e = true ) { $o = (string) $a === (string) $b ? ' disabled="disabled"' : ''; if ( $e ) { echo $o; } return $o; }
function get_locale() { return $GLOBALS['locale'] ?? 'en_US'; }
function get_bloginfo( $k ) { return 'name' === $k ? 'Test Site' : ( 'admin_email' === $k ? 'admin@example.test' : 'en-US' ); }
function home_url( $p = '' ) { return 'http://example.test' . ( $p ? '/' . ltrim( $p, '/' ) : '' ); }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . ltrim( $p, '/' ); }
function wp_login_url( $r = '' ) { return 'http://example.test/wp-login.php'; }
function wp_logout_url() { return 'http://example.test/wp-login.php?action=logout'; }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; } function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function add_query_arg( ...$a ) { if ( is_array( $a[0] ) ) { $args = $a[0]; $url = $a[1] ?? ''; } else { $args = array( $a[0] => $a[1] ); $url = $a[2] ?? ''; } $parts = explode( '?', (string) $url, 2 ); $q = array(); if ( isset( $parts[1] ) ) { parse_str( $parts[1], $q ); } $q = array_merge( $q, $args ); foreach ( $q as $k => $v ) { if ( false === $v ) { unset( $q[ $k ] ); } } return $parts[0] . ( $q ? '?' . http_build_query( $q ) : '' ); }
function remove_query_arg( $k, $url ) { return add_query_arg( array_fill_keys( (array) $k, false ), $url ); }
function paginate_links( $a ) { return $a['total'] > 1 ? '<span class="pages">' . $a['total'] . ' pages</span>' : ''; }
function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
function wp_date( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); } function date_i18n( $f, $ts = null ) { return gmdate( $f, $ts ?? time() ); }
function current_time( $t, $gmt = 0 ) { return 'mysql' === $t ? gmdate( 'Y-m-d H:i:s' ) : ( 'timestamp' === $t ? time() : gmdate( $t ) ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function wp_json_encode( $v, $f = 0, $d = 512 ) { return json_encode( $v, $f, $d ); }
function wp_parse_args( $a, $d = array() ) { if ( is_object( $a ) ) { $a = get_object_vars( $a ); } elseif ( is_string( $a ) ) { parse_str( $a, $a ); } return array_merge( (array) $d, (array) $a ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_list_pluck( $l, $k, $i = null ) { $o = array(); foreach ( $l as $r ) { $v = is_object( $r ) ? $r->$k : $r[ $k ]; if ( $i ) { $o[ is_object( $r ) ? $r->$i : $r[ $i ] ] = $v; } else { $o[] = $v; } } return $o; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_trim_words( $s, $n = 55, $m = '…' ) { $w = preg_split( '/\s+/', trim( (string) $s ) ); return count( $w ) > $n ? implode( ' ', array_slice( $w, 0, $n ) ) . $m : $s; }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { $s = preg_replace( '/[^\p{L}\p{N}\-]+/u', '-', strtolower( trim( (string) $s ) ) ); return trim( $s, '-' ); }
function sanitize_file_name( $s ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $s ); }
function sanitize_email( $s ) { return (string) filter_var( $s, FILTER_SANITIZE_EMAIL ); }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function absint( $v ) { return abs( (int) $v ); }
function wp_rand( $a = 0, $b = 0 ) { return $b ? random_int( $a, $b ) : random_int( 0, PHP_INT_MAX ); }
function wp_generate_password( $n = 12, $s = true, $e = false ) { return substr( bin2hex( random_bytes( $n ) ), 0, $n ); }
function wp_hash( $d, $s = 'auth' ) { return hash_hmac( 'md5', $d, wp_salt( $s ) ); }
function wp_salt( $s = 'auth' ) { return 'salt-' . $s; }
function wp_create_nonce( $a = -1 ) { return substr( wp_hash( $a . '|' . get_current_user_id() ), -12 ); }
function wp_verify_nonce( $n, $a = -1 ) { return wp_create_nonce( $a ) === $n ? 1 : false; }
function wp_nonce_field( $a = -1, $n = '_wpnonce', $r = true, $e = true ) { $o = '<input type="hidden" name="' . $n . '" value="' . wp_create_nonce( $a ) . '">'; if ( $e ) { echo $o; } return $o; }
function wp_nonce_url( $u, $a = -1, $n = '_wpnonce' ) { return add_query_arg( $n, wp_create_nonce( $a ), $u ); }
function check_admin_referer( $a = -1, $n = '_wpnonce' ) { $v = wp_verify_nonce( $_REQUEST[ $n ] ?? '', $a ); if ( ! $v ) { wp_die( 'The link you followed has expired.' ); } return $v; }
function check_ajax_referer( $a = -1, $n = false, $die = true ) { return true; }
function wp_get_referer() { return false; }
class PCT_Test_Exit extends Exception {}
function wp_die( $m = '', $t = '', $a = array() ) { throw new PCT_Test_Exit( is_wp_error( $m ) ? $m->get_error_message() : (string) $m ); }
function wp_redirect( $l, $s = 302 ) { $GLOBALS['redirect'] = $l; return true; }
function wp_safe_redirect( $l, $s = 302 ) { $GLOBALS['redirect'] = $l; return true; }
function nocache_headers() {}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

/* options/transients/cache */
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function add_option( $k, $v ) { if ( ! isset( $GLOBALS['opts'][ $k ] ) ) { $GLOBALS['opts'][ $k ] = $v; } return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function wp_cache_get( $k, $g = '' ) { return $GLOBALS['cache'][ $g ][ $k ] ?? false; }
function wp_cache_set( $k, $v, $g = '', $t = 0 ) { $GLOBALS['cache'][ $g ][ $k ] = $v; return true; }
function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['cache'][ $g ][ $k ] ); return true; }

/* users */
function pct_seed_user( $id, $login, $roles, $caps = array() ) { $GLOBALS['users'][ $id ] = array( 'login' => $login, 'email' => $login . '@example.test', 'name' => ucfirst( $login ), 'roles' => $roles ); $GLOBALS['caps'][ $id ] = $caps; }
function wp_set_current_user( $id ) { $GLOBALS['current_user'] = (int) $id; }
function get_current_user_id() { return $GLOBALS['current_user']; }
function is_user_logged_in() { return $GLOBALS['current_user'] > 0; }
function wp_get_current_user() { return new WP_User( $GLOBALS['current_user'] ); }
function get_userdata( $id ) { return isset( $GLOBALS['users'][ $id ] ) ? new WP_User( $id ) : false; }
function get_user_by( $f, $v ) { foreach ( $GLOBALS['users'] as $id => $u ) { if ( ( 'id' === $f || 'ID' === $f ) && $id == $v ) { return new WP_User( $id ); } if ( 'login' === $f && $u['login'] === $v ) { return new WP_User( $id ); } if ( 'email' === $f && $u['email'] === $v ) { return new WP_User( $id ); } } return false; }
function get_users( $a = array() ) { $o = array(); foreach ( $GLOBALS['users'] as $id => $u ) { if ( isset( $a['role'] ) && ! in_array( $a['role'], $u['roles'], true ) ) { continue; } if ( isset( $a['role__in'] ) && ! array_intersect( $a['role__in'], $u['roles'] ) ) { continue; } if ( isset( $a['capability'] ) && ! user_can( $id, $a['capability'] ) ) { continue; } $o[] = new WP_User( $id ); } return $o; }
function user_can( $u, $c ) { $id = $u instanceof WP_User ? $u->ID : (int) $u; if ( ! $id ) { return false; } if ( ! empty( $GLOBALS['caps'][ $id ][ $c ] ) ) { return true; } foreach ( $GLOBALS['users'][ $id ]['roles'] ?? array() as $r ) { $ro = get_role( $r ); if ( $ro && $ro->has_cap( $c ) ) { return true; } if ( 'administrator' === $r ) { return true; } } return false; }
function current_user_can( $c ) { return user_can( get_current_user_id(), $c ); }
function get_user_meta( $id, $k = '', $s = false ) { return $GLOBALS['umeta'][ $id ][ $k ] ?? ( $s ? '' : array() ); }
function update_user_meta( $id, $k, $v ) { $GLOBALS['umeta'][ $id ][ $k ] = $v; return true; }
function delete_user_meta( $id, $k ) { unset( $GLOBALS['umeta'][ $id ][ $k ] ); return true; }
function get_edit_user_link( $id ) { return admin_url( 'user-edit.php?user_id=' . $id ); }
function count_user_posts( $id, $t = 'post' ) { $n = 0; foreach ( $GLOBALS['posts'] as $p ) { if ( $p->post_author == $id && in_array( $p->post_type, (array) $t, true ) ) { $n++; } } return $n; }

/* posts */
function pct_seed( $id, $type, $title, $meta = array(), $cats = array(), $content = 'Lorem ipsum dolor sit amet consectetur.', $extra = array() ) { $GLOBALS['posts'][ $id ] = new WP_Post( array_merge( array( 'ID' => $id, 'post_type' => $type, 'post_title' => $title, 'post_content' => $content, 'post_excerpt' => '', 'post_status' => 'publish', 'post_author' => 1, 'post_name' => sanitize_title( $title ), 'post_date' => gmdate( 'Y-m-d H:i:s' ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified' => gmdate( 'Y-m-d H:i:s' ), 'post_parent' => 0 ), $extra ) ); $GLOBALS['meta'][ $id ] = $meta; $GLOBALS['terms'][ $id ] = array( 'pct_category' => $cats, 'pct_tag' => array( 'tag-' . $id ) ); }
function get_post( $id = null ) { if ( $id instanceof WP_Post ) { return $id; } return $GLOBALS['posts'][ (int) $id ] ?? null; }
function get_post_field( $f, $id ) { $p = get_post( $id ); return $p ? ( $p->$f ?? '' ) : ''; }
function get_post_status( $id ) { $p = get_post( $id ); return $p ? $p->post_status : false; }
function get_post_type( $id = null ) { $p = get_post( $id ); return $p ? $p->post_type : false; }
function get_the_title( $id ) { $p = get_post( $id ); return $p ? $p->post_title : ''; }
function get_permalink( $id ) { $p = get_post( $id ); return $p ? home_url( '/' . $p->post_type . '/' . $p->post_name . '/' ) : ''; }
function get_edit_post_link( $id ) { return admin_url( 'post.php?post=' . (int) $id . '&action=edit' ); }
function get_post_meta( $id, $k = '', $single = false ) { if ( '' === $k ) { return $GLOBALS['meta'][ $id ] ?? array(); } if ( ! isset( $GLOBALS['meta'][ $id ][ $k ] ) ) { return $single ? '' : array(); } return $single ? $GLOBALS['meta'][ $id ][ $k ] : array( $GLOBALS['meta'][ $id ][ $k ] ); }
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; return true; }
function delete_post_meta( $id, $k ) { unset( $GLOBALS['meta'][ $id ][ $k ] ); return true; }
function wp_get_post_terms( $id, $tax, $a = array() ) { $t = $GLOBALS['terms'][ $id ][ $tax ] ?? array(); if ( isset( $a['fields'] ) && 'names' === $a['fields'] ) { return $t; } if ( isset( $a['fields'] ) && 'slugs' === $a['fields'] ) { return array_map( 'sanitize_title', $t ); } return array_map( function ( $n ) { return (object) array( 'name' => $n, 'slug' => sanitize_title( $n ), 'term_id' => crc32( $n ) % 1000 ); }, $t ); }
function wp_set_object_terms( $id, $terms, $tax ) { $GLOBALS['terms'][ $id ][ $tax ] = array_values( (array) $terms ); return true; }
function get_term_by( $f, $v, $tax ) { return (object) array( 'name' => ucfirst( (string) $v ), 'slug' => sanitize_title( (string) $v ), 'term_id' => crc32( (string) $v ) % 1000 ); }
function get_terms( $a = array() ) { return array(); }
function term_exists( $t, $tax ) { return false; }
function wp_insert_term( $t, $tax ) { return array( 'term_id' => crc32( $t ) % 1000 ); }
function wp_insert_post( $a, $err = false ) { $id = $GLOBALS['next_id']++; $a = wp_parse_args( $a, array( 'post_type' => 'post', 'post_title' => '', 'post_content' => '', 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_name' => '' ) ); $meta = $a['meta_input'] ?? array(); unset( $a['meta_input'] ); if ( ! $a['post_name'] ) { $a['post_name'] = sanitize_title( $a['post_title'] ) ?: 'post-' . $id; } pct_seed( $id, $a['post_type'], $a['post_title'], $meta, array(), $a['post_content'], $a ); return $id; }
function wp_update_post( $a ) { $id = (int) $a['ID']; if ( ! isset( $GLOBALS['posts'][ $id ] ) ) { return 0; } foreach ( $a as $k => $v ) { if ( 'meta_input' === $k ) { foreach ( $v as $mk => $mv ) { update_post_meta( $id, $mk, $mv ); } } else { $GLOBALS['posts'][ $id ]->$k = $v; } } return $id; }
function wp_delete_post( $id, $f = false ) { $p = $GLOBALS['posts'][ $id ] ?? null; unset( $GLOBALS['posts'][ $id ], $GLOBALS['meta'][ $id ] ); return $p; }
function wp_trash_post( $id ) { if ( isset( $GLOBALS['posts'][ $id ] ) ) { $GLOBALS['posts'][ $id ]->post_status = 'trash'; } return true; }
function wp_is_post_revision() { return false; }
function wp_unique_post_slug( $s ) { return $s; }
function wp_count_posts( $t ) { $o = array( 'publish' => 0, 'pending' => 0, 'draft' => 0, 'private' => 0 ); foreach ( $GLOBALS['posts'] as $p ) { if ( $p->post_type === $t ) { $o[ $p->post_status ] = ( $o[ $p->post_status ] ?? 0 ) + 1; } } return (object) $o; }
function pct_meta_match( $id, $q ) { $v = $GLOBALS['meta'][ $id ][ $q['key'] ] ?? null; $c = strtoupper( $q['compare'] ?? '=' ); if ( 'EXISTS' === $c ) { return null !== $v; } if ( 'NOT EXISTS' === $c ) { return null === $v; } if ( 'IN' === $c ) { return in_array( $v, (array) $q['value'] ); } if ( 'NOT IN' === $c ) { return ! in_array( $v, (array) $q['value'] ); } if ( '>=' === $c ) { return $v >= $q['value']; } if ( '<=' === $c ) { return $v <= $q['value']; } if ( '>' === $c ) { return $v > $q['value']; } if ( '<' === $c ) { return $v < $q['value']; } if ( '!=' === $c ) { return $v != $q['value']; } if ( 'LIKE' === $c ) { return false !== stripos( (string) $v, (string) $q['value'] ); } return $v == $q['value']; }
function get_posts( $args = array() ) {
	$out = array(); $types = (array) ( $args['post_type'] ?? 'post' ); $status = (array) ( $args['post_status'] ?? 'publish' );
	foreach ( $GLOBALS['posts'] as $p ) {
		if ( ! in_array( $p->post_type, $types, true ) && ! in_array( 'any', $types, true ) ) { continue; }
		if ( ! in_array( 'any', $status, true ) && ! in_array( $p->post_status, $status, true ) ) { continue; }
		if ( isset( $args['author'] ) && $p->post_author != $args['author'] ) { continue; }
		if ( ! empty( $args['include'] ) && ! in_array( $p->ID, array_map( 'intval', (array) $args['include'] ), true ) ) { continue; }
		if ( ! empty( $args['exclude'] ) && in_array( $p->ID, array_map( 'intval', (array) $args['exclude'] ), true ) ) { continue; }
		if ( ! empty( $args['name'] ) && $p->post_name !== $args['name'] ) { continue; }
		if ( ! empty( $args['s'] ) && false === stripos( $p->post_title . ' ' . $p->post_content, $args['s'] ) ) { continue; }
		if ( isset( $args['meta_key'] ) && isset( $args['meta_value'] ) && ! pct_meta_match( $p->ID, array( 'key' => $args['meta_key'], 'value' => $args['meta_value'] ) ) ) { continue; }
		if ( ! empty( $args['meta_query'] ) ) { $rel = strtoupper( $args['meta_query']['relation'] ?? 'AND' ); $ok = 'AND' === $rel; foreach ( $args['meta_query'] as $k => $q ) { if ( 'relation' === $k ) { continue; } $m = pct_meta_match( $p->ID, $q ); if ( 'AND' === $rel && ! $m ) { $ok = false; break; } if ( 'OR' === $rel && $m ) { $ok = true; } } if ( ! $ok ) { continue; } }
		if ( ! empty( $args['tax_query'] ) ) { $ok = true; foreach ( $args['tax_query'] as $k => $q ) { if ( 'relation' === $k ) { continue; } $have = array_map( 'sanitize_title', $GLOBALS['terms'][ $p->ID ][ $q['taxonomy'] ] ?? array() ); if ( ! array_intersect( array_map( 'sanitize_title', (array) $q['terms'] ), $have ) ) { $ok = false; } } if ( ! $ok ) { continue; } }
		$out[] = $p;
	}
	if ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) { $out = wp_list_pluck( $out, 'ID' ); }
	$n = (int) ( $args['numberposts'] ?? $args['posts_per_page'] ?? -1 ); if ( $n > 0 ) { $out = array_slice( $out, 0, $n ); }
	return $out;
}
class WP_Query { public $posts = array(); public $found_posts = 0; public $post_count = 0; public function __construct( $a = array() ) { $this->posts = get_posts( $a ); $this->found_posts = count( $this->posts ); $this->post_count = $this->found_posts; } public function have_posts() { return false; } }

/* uploads / files */
function wp_upload_dir() { $d = dirname( __DIR__ ) . '/.out/uploads'; if ( ! is_dir( $d ) ) { mkdir( $d, 0777, true ); } return array( 'path' => $d, 'url' => 'http://example.test/uploads', 'subdir' => '', 'basedir' => $d, 'baseurl' => 'http://example.test/uploads', 'error' => false ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_upload_bits( $name, $dep, $bits ) { $u = wp_upload_dir(); $f = $u['path'] . '/' . sanitize_file_name( $name ); file_put_contents( $f, $bits ); return array( 'file' => $f, 'url' => $u['url'] . '/' . basename( $f ), 'error' => false ); }
function wp_handle_upload( $file, $o = array() ) { $u = wp_upload_dir(); $f = $u['path'] . '/' . sanitize_file_name( $file['name'] ); copy( $file['tmp_name'], $f ); return array( 'file' => $f, 'url' => $u['url'] . '/' . basename( $f ), 'type' => $file['type'] ); }
function wp_check_filetype_and_ext( $f, $n ) { $ext = strtolower( pathinfo( $n, PATHINFO_EXTENSION ) ); $map = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'json' => 'application/json' ); return array( 'ext' => isset( $map[ $ext ] ) ? $ext : false, 'type' => $map[ $ext ] ?? false, 'proper_filename' => false ); }
function wp_insert_attachment( $a, $f = '', $p = 0 ) { $a['post_type'] = 'attachment'; $a['post_status'] = 'inherit'; $id = wp_insert_post( $a ); update_post_meta( $id, '_wp_attached_file', basename( $f ) ); return $id; }
function wp_generate_attachment_metadata() { return array(); } function wp_update_attachment_metadata() { return true; }
function wp_get_attachment_url( $id ) { $f = get_post_meta( $id, '_wp_attached_file', true ); return $f ? 'http://example.test/uploads/' . $f : false; }
function wp_delete_attachment( $id ) { return wp_delete_post( $id ); }
function wp_delete_file( $f ) { if ( file_exists( $f ) ) { unlink( $f ); } }
function wp_tempnam( $n = '' ) { return tempnam( sys_get_temp_dir(), 'pct' ); }
function wp_mail( $to, $s, $m, $h = array() ) { $GLOBALS['mail'][] = compact( 'to', 's', 'm' ); return true; }

/* http */
function wp_remote_request( $url, $args = array() ) { $GLOBALS['http'][] = array( 'url' => $url, 'args' => $args ); if ( $GLOBALS['http_handler'] ) { return call_user_func( $GLOBALS['http_handler'], $url, $args ); } return new WP_Error( 'http_request_failed', 'no network' ); }
function wp_remote_post( $url, $args = array() ) { $args['method'] = 'POST'; return wp_remote_request( $url, $args ); }
function wp_remote_get( $url, $args = array() ) { $args['method'] = 'GET'; return wp_remote_request( $url, $args ); }
function wp_remote_retrieve_body( $r ) { return is_wp_error( $r ) ? '' : ( $r['body'] ?? '' ); }
function wp_remote_retrieve_response_code( $r ) { return is_wp_error( $r ) ? 0 : ( $r['response']['code'] ?? 200 ); }
function wp_remote_retrieve_header( $r, $h ) { return is_wp_error( $r ) ? '' : ( $r['headers'][ strtolower( $h ) ] ?? '' ); }
function size_format( $bytes, $d = 0 ) { $u = array( 'B', 'KB', 'MB', 'GB', 'TB' ); $i = 0; while ( $bytes >= 1024 && $i < 4 ) { $bytes /= 1024; $i++; } return round( $bytes, $d ) . ' ' . $u[ $i ]; }
function wp_remote_retrieve_response_message( $r ) { return is_wp_error( $r ) ? '' : ( $r['response']['message'] ?? 'OK' ); }
function pct_http_response( $body, $code = 200 ) { return array( 'body' => is_string( $body ) ? $body : json_encode( $body ), 'response' => array( 'code' => $code, 'message' => 'OK' ) ); }

/* cron */
function wp_next_scheduled( $h, $a = array() ) { return $GLOBALS['cron'][ $h ] ?? false; }
function wp_schedule_event( $t, $r, $h, $a = array() ) { $GLOBALS['cron'][ $h ] = $t; return true; }
function wp_schedule_single_event( $t, $h, $a = array() ) { $GLOBALS['cron'][ $h ] = $t; return true; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['cron'][ $h ] ); }
function wp_get_schedules() { return array( 'hourly' => array( 'interval' => 3600, 'display' => 'Hourly' ), 'twicedaily' => array( 'interval' => 43200, 'display' => 'Twice daily' ), 'daily' => array( 'interval' => 86400, 'display' => 'Daily' ), 'weekly' => array( 'interval' => 604800, 'display' => 'Weekly' ) ); }

/* wpdb */
class PCT_Fake_WPDB {
	public $prefix = 'wp_'; public $rows = array(); public $auto = 1; public $insert_id = 0; public $last_error = '';
	public function get_charset_collate() { return ''; }
	public function prepare( $q, ...$a ) { if ( 1 === count( $a ) && is_array( $a[0] ) ) { $a = $a[0]; } $q = str_replace( '%i', '%s', $q ); foreach ( $a as $v ) { $q = preg_replace( '/%[dsf]/', is_numeric( $v ) ? $v : "'" . addslashes( $v ) . "'", $q, 1 ); } return $q; }
	public function insert( $t, $row ) { $row['id'] = $this->auto++; $this->rows[ $row['id'] ] = $row; $this->insert_id = $row['id']; return 1; }
	public function query( $q ) { if ( 0 === stripos( $q, 'TRUNCATE' ) ) { $n = count( $this->rows ); $this->rows = array(); return $n; } if ( preg_match( "/DELETE FROM \S+ WHERE created < '([^']+)'/i", $q, $m ) ) { $n = 0; foreach ( $this->rows as $id => $r ) { if ( $r['created'] < $m[1] ) { unset( $this->rows[ $id ] ); $n++; } } return $n; } return 0; }
	private function filter( $q ) { $rows = $this->rows; if ( preg_match_all( "/(\w+) = '([^']*)'/", $q, $mm, PREG_SET_ORDER ) ) { foreach ( $mm as $m ) { if ( in_array( $m[1], array( 'kind', 'status', 'provider' ), true ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r[ $m[1] ] === $m[2]; } ); } } } if ( preg_match( "/created >= '([^']+)'/", $q, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return $r['created'] >= $m[1]; } ); } if ( false !== stripos( $q, "kind <> 'audit'" ) ) { $rows = array_filter( $rows, function ( $r ) { return 'audit' !== $r['kind']; } ); } if ( preg_match( '/id = (\d+)/', $q, $m ) ) { $rows = array_filter( $rows, function ( $r ) use ( $m ) { return (int) $r['id'] === (int) $m[1]; } ); } return array_values( $rows ); }
	public function get_results( $q, $o = OBJECT ) { $rows = $this->filter( $q ); if ( false !== stripos( $q, 'GROUP BY status' ) ) { $g = array(); foreach ( $rows as $r ) { $g[ $r['status'] ]['n'] = ( $g[ $r['status'] ]['n'] ?? 0 ) + 1; $g[ $r['status'] ]['sum'] = ( $g[ $r['status'] ]['sum'] ?? 0 ) + (int) ( $r['duration_ms'] ?? 0 ); } $o2 = array(); foreach ( $g as $s => $v ) { $o2[] = array( 'status' => $s, 'n' => $v['n'], 'avg_ms' => $v['sum'] / $v['n'] ); } return $o2; } if ( false !== stripos( $q, 'DESC' ) ) { $rows = array_reverse( $rows ); } if ( preg_match( '/LIMIT (\d+)(?:,| OFFSET) (\d+)/i', $q, $m ) ) { $rows = array_slice( $rows, (int) $m[2], (int) $m[1] ); } elseif ( preg_match( '/LIMIT (\d+)/i', $q, $m ) ) { $rows = array_slice( $rows, 0, (int) $m[1] ); } return ARRAY_A === $o ? $rows : array_map( function ( $r ) { return (object) $r; }, $rows ); }
	public function get_var( $q ) { if ( false !== stripos( $q, 'COUNT(*)' ) ) { return count( $this->filter( $q ) ); } $r = $this->filter( $q ); return $r ? reset( $r[0] ) : null; }
	public function get_row( $q, $o = OBJECT ) { $r = $this->filter( $q ); if ( ! $r ) { return null; } return ARRAY_A === $o ? $r[0] : (object) $r[0]; }
}
$GLOBALS['wpdb'] = new PCT_Fake_WPDB();
function dbDelta( $sql ) { return array(); }

/* bootstrap plugin */
define( 'PCT_TEST_PLUGIN_FILE', dirname( __DIR__, 2 ) . '/personalized-city-tour/personalized-city-tour.php' );
function pct_boot( $admin = false ) {
	$GLOBALS['is_admin'] = $admin;
	require_once PCT_TEST_PLUGIN_FILE;
	if ( isset( $GLOBALS['activation'] ) ) { call_user_func( $GLOBALS['activation'] ); }
	do_action( 'plugins_loaded' );
	do_action( 'init' );
	do_action( 'rest_api_init' );
	if ( $admin ) { do_action( 'admin_init' ); do_action( 'admin_menu' ); }
}
function pct_assert( $cond, $msg ) { if ( ! $cond ) { echo "  FAIL: $msg\n"; $GLOBALS['fails']++; } else { echo "  ok: $msg\n"; } }
function pct_render( $cb, ...$args ) { ob_start(); try { call_user_func_array( $cb, $args ); } catch ( PCT_Test_Exit $e ) { ob_end_clean(); return 'EXIT: ' . $e->getMessage(); } return ob_get_clean(); }
function pct_seed_city() {
	pct_seed( 1, 'pct_city', 'Testville', array( '_pct_country' => 'Utopia', '_pct_lat' => 48.85, '_pct_lng' => 2.35, '_pct_zoom' => 13, '_pct_mymaps' => array( array( 'label' => 'Best of', 'url' => 'https://www.google.com/maps/d/viewer?mid=abc123' ) ), '_pct_google_url' => 'https://maps.google.com/?q=Testville', '_pct_channels' => array( array( 'label' => 'Telegram', 'url' => 'https://t.me/testville' ) ) ) );
	$cats = array( 'historical', 'museums', 'food', 'artistic', 'nature', 'shopping', 'markets', 'viewpoints', 'nightlife', 'cultural' );
	for ( $i = 0; $i < 12; $i++ ) {
		pct_seed( 10 + $i, 'pct_place', 'Place ' . $i, array( '_pct_city_id' => 1, '_pct_lat' => 48.85 + $i * 0.004, '_pct_lng' => 2.35 + ( $i % 3 ) * 0.005, '_pct_duration' => 60 + $i * 10, '_pct_price_tier' => $i % 2 ? 'low' : 'free', '_pct_accessibility' => $i % 2 ? array( 'wheelchair' ) : array(), '_pct_best_time' => array( 'morning' ), '_pct_address' => $i . ' Main St', '_pct_google_url' => 'https://maps.google.com/?q=Place+' . $i ), array( $cats[ $i % count( $cats ) ] ) );
	}
	pct_seed( 30, 'pct_event', 'Night Market', array( '_pct_city_id' => 1, '_pct_lat' => 48.86, '_pct_lng' => 2.36, '_pct_start' => gmdate( 'Y-m-d', strtotime( '+2 days' ) ) . ' 18:00', '_pct_end' => gmdate( 'Y-m-d', strtotime( '+2 days' ) ) . ' 23:00', '_pct_organizer_name' => 'City Org', '_pct_organizer_url' => 'https://org.example', '_pct_event_url' => 'https://event.example', '_pct_accessibility' => array( 'wheelchair' ) ), array( 'markets', 'food' ) );
}
