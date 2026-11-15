<?php

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SAMOMIND_Orders_State
 *
 * The one small table behind the order checks (design sections 3 and 5):
 * nonces, code records with their try counter, grants, one-time account
 * records and the send counters. Every decision is made by the database in
 * one statement and read from the number of rows it changed, so two
 * requests at the same moment cannot both win. Transients and options are
 * not used for this: add_option() is not atomic, and a persistent object
 * cache would serve stale copies. $wpdb is used directly and the object
 * cache is never consulted.
 *
 * @package     SAMOMIND
 * @subpackage  Classes/SAMOMIND_Orders_State
 * @since       0.4.0
 */
class SAMOMIND_Orders_State {

	/**
	 * Version of the table layout, kept in an option so an upgrade can
	 * create or change it.
	 *
	 * @var string
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed table version.
	 *
	 * @var string
	 */
	const DB_OPTION = 'samomind_orders_db_version';

	/**
	 * Table name without the site prefix.
	 *
	 * @var string
	 */
	const TABLE = 'samomind_orders_state';

	/**
	 * The full table name.
	 *
	 * @since   0.4.0
	 * @return  string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * The lookup key of a record: a hash of what identifies it.
	 *
	 * @since   0.4.0
	 * @param   string[] $parts  What identifies it.
	 * @return  string  64 hex characters.
	 */
	public static function key( array $parts ) {
		return hash( 'sha256', implode( '|', $parts ) );
	}

	/**
	 * Create or update the table with dbDelta.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = esc_sql( self::table() );
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(16) NOT NULL,
  state_key char(64) NOT NULL,
  session_ref char(32) NOT NULL DEFAULT '',
  n int(10) unsigned NOT NULL DEFAULT 0,
  expires bigint(20) unsigned NOT NULL,
  data text NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY kind_key (kind,state_key),
  KEY session_ref (session_ref),
  KEY expires (expires)
) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Install the table when an orders key is saved and the version on disk
	 * is not the one the code wants. A site that never sets the feature up
	 * gets no table.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	public static function maybe_install() {
		if ( self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) {
			return;
		}
		if ( '' === (string) get_option( SAMOMIND_Orders_Settings::SECRET, '' ) && '1' !== (string) get_option( SAMOMIND_Orders_Settings::OPT_IN, '0' ) ) {
			return;
		}
		self::install();
	}

	/**
	 * Remove the table and its version (uninstall).
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	public static function drop() {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- removing the plugin's own table.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		delete_option( self::DB_OPTION );
	}

	/**
	 * Delete every expired row. Runs on the plugin's hourly hook.
	 *
	 * @since   0.4.0
	 * @return  void
	 */
	public static function purge_expired() {
		global $wpdb;
		if ( self::DB_VERSION !== (string) get_option( self::DB_OPTION, '' ) ) {
			return;
		}
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table, expired rows only.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires <= %d", time() ) );
	}

	/**
	 * Take a key that nobody holds. An expired holder is removed first. The
	 * UNIQUE key lets exactly one of several simultaneous callers insert.
	 *
	 * @since   0.4.0
	 * @param   string $kind     Kind of record.
	 * @param   string $key      Output of key().
	 * @param   int    $expires  Unix time it ends.
	 * @param   string $session  Session reference, or an empty string.
	 * @return  int|false  1 when taken now, 0 when someone holds it, false on a database error.
	 */
	public static function claim( $kind, $key, $expires, $session = '' ) {
		global $wpdb;
		$table = esc_sql( self::table() );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the object cache must not be used.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE kind = %s AND state_key = %s AND expires <= %d", $kind, $key, time() ) );
		$rows = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (kind, state_key, session_ref, n, expires) VALUES (%s, %s, %s, 0, %d)", $kind, $key, $session, (int) $expires ) );
		// phpcs:enable

		return false === $rows ? false : (int) $rows;
	}

	/**
	 * Count one use of a key and say whether it was within the limit. The
	 * increment is one conditional UPDATE, so the counter can never pass
	 * the limit however many callers arrive together.
	 *
	 * @since   0.4.0
	 * @param   string $kind     Kind of counter.
	 * @param   string $key      Output of key().
	 * @param   int    $limit    Most uses.
	 * @param   int    $expires  Unix time a new counter ends.
	 * @param   string $session  Session reference, or an empty string.
	 * @return  bool
	 */
	public static function bump( $kind, $key, $limit, $expires, $session = '' ) {
		global $wpdb;
		$table = esc_sql( self::table() );

		if ( false === self::claim( $kind, $key, $expires, $session ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the object cache must not be used.
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET n = n + 1 WHERE kind = %s AND state_key = %s AND n < %d AND expires > %d", $kind, $key, (int) $limit, time() ) );
		return 1 === $rows;
	}

	/**
	 * The count of a live counter, 0 when there is none.
	 *
	 * @since   0.4.0
	 * @param   string $kind  Kind of counter.
	 * @param   string $key   Output of key().
	 * @return  int
	 */
	public static function count( $kind, $key ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the object cache must not be used.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT n FROM {$table} WHERE kind = %s AND state_key = %s AND expires > %d", $kind, $key, time() ) );
	}

	/**
	 * Write a record, replacing one with the same key (its counter starts
	 * again at 0).
	 *
	 * @since   0.4.0
	 * @param   string $kind     Kind of record.
	 * @param   string $key      Output of key().
	 * @param   int    $expires  Unix time it ends; fixed, never moved.
	 * @param   array  $data     What to keep.
	 * @param   string $session  Session reference, or an empty string.
	 * @return  bool
	 */
	public static function put( $kind, $key, $expires, array $data, $session = '' ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the object cache must not be used.
		$rows = $wpdb->query( $wpdb->prepare( "REPLACE INTO {$table} (kind, state_key, session_ref, n, expires, data) VALUES (%s, %s, %s, 0, %d, %s)", $kind, $key, $session, (int) $expires, wp_json_encode( $data ) ) );
		return false !== $rows;
	}

	/**
	 * Read a live record without changing it.
	 *
	 * @since   0.4.0
	 * @param   string $kind  Kind of record.
	 * @param   string $key   Output of key().
	 * @return  array|null  id, n, expires and data, or null.
	 */
	public static function get( $kind, $key ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the object cache must not be used.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, n, expires, data FROM {$table} WHERE kind = %s AND state_key = %s AND expires > %d", $kind, $key, time() ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$data = json_decode( (string) $row['data'], true );
		return array(
			'id'      => (int) $row['id'],
			'n'       => (int) $row['n'],
			'expires' => (int) $row['expires'],
			'data'    => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Count one try against a record. The UPDATE only matches while the
	 * record is live and under the limit, so parallel guesses are counted
	 * one by one and the ones past the limit are refused.
	 *
	 * @since   0.4.0
	 * @param   int $id     Row id from get().
	 * @param   int $limit  Tries allowed.
	 * @return  bool  False when the record is locked, expired or gone.
	 */
	public static function try_once( $id, $limit ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the object cache must not be used.
		return 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET n = n + 1 WHERE id = %d AND n < %d AND expires > %d", (int) $id, (int) $limit, time() ) );
	}

	/**
	 * Delete one row by id. Of several callers only one sees true.
	 *
	 * @since   0.4.0
	 * @param   int $id  Row id.
	 * @return  bool
	 */
	public static function spend( $id ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the object cache must not be used.
		return 1 === $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Take a record once: read it, then delete it, and only the caller whose
	 * DELETE changed a row gets it. An expired record is deleted and gives
	 * nothing.
	 *
	 * @since   0.4.0
	 * @param   string $kind  Kind of record.
	 * @param   string $key   Output of key().
	 * @return  array|null  The record's data, for the one winner.
	 */
	public static function take( $kind, $key ) {
		global $wpdb;
		$table = esc_sql( self::table() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table; the object cache must not be used.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, expires, data FROM {$table} WHERE kind = %s AND state_key = %s", $kind, $key ), ARRAY_A );
		if ( ! is_array( $row ) || ! self::spend( (int) $row['id'] ) || (int) $row['expires'] <= time() ) {
			return null;
		}
		$data = json_decode( (string) $row['data'], true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Delete rows of the given kinds for one session.
	 *
	 * @since   0.4.0
	 * @param   string   $session  Session reference.
	 * @param   string[] $kinds    Kinds to delete.
	 * @return  void
	 */
	public static function delete_session( $session, array $kinds ) {
		global $wpdb;
		$table        = esc_sql( self::table() );
		$placeholders = implode( ', ', array_fill( 0, count( $kinds ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.UnfinishedPrepare -- the plugin's own table; the placeholders are generated above.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE session_ref = %s AND kind IN ({$placeholders})", array_merge( array( $session ), $kinds ) ) );
	}
}
