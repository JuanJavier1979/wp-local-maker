<?php
 /**
  * Plugin Name: WP Local Maker
  * Plugin URI: https://www.saucal.com
  * Description: WP CLI Exports with reduced datasets
  * Version: 1.0.0
  * Author: SAU/CAL
  * Author URI: https://www.saucal.com
  */

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

use WP_CLI\Formatter;
use \WP_CLI\Utils;

/**
 * Perform backups of the database with reduced data sets
 *
 * ## EXAMPLES
 *
 *     # Create a new database.
 *     $ wp db create
 *     Success: Database created.
 *
 *     # Drop an existing database.
 *     $ wp db drop --yes
 *     Success: Database dropped.
 *
 *     # Reset the current database.
 *     $ wp db reset --yes
 *     Success: Database reset.
 *
 *     # Execute a SQL query stored in a file.
 *     $ wp db query < debug.sql
 *
 * @when after_wp_config_load
 */
class Backup_Command extends WP_CLI_Command {

	protected static $tables_info = null;

	/**
	 * Exports the database to a file or to STDOUT.
	 *
	 * Runs `mysqldump` utility using `DB_HOST`, `DB_NAME`, `DB_USER` and
	 * `DB_PASSWORD` database credentials specified in wp-config.php.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : The name of the SQL file to export. If '-', then outputs to STDOUT. If
	 * omitted, it will be '{dbname}-{Y-m-d}-{random-hash}.sql'.
	 *
	 * [--dbuser=<value>]
	 * : Username to pass to mysqldump. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to pass to mysqldump. Defaults to DB_PASSWORD.
	 *
	 * [--<field>=<value>]
	 * : Extra arguments to pass to mysqldump.
	 *
	 * [--tables=<tables>]
	 * : The comma separated list of specific tables to export. Excluding this parameter will export all tables in the database.
	 *
	 * [--exclude_tables=<tables>]
	 * : The comma separated list of specific tables that should be skipped from exporting. Excluding this parameter will export all tables in the database.
	 *
	 * [--porcelain]
	 * : Output filename for the exported database.
	 *
	 * ## EXAMPLES
	 *
	 *     # Export database with drop query included
	 *     $ wp db export --add-drop-table
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export certain tables
	 *     $ wp db export --tables=wp_options,wp_users
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export all tables matching a wildcard
	 *     $ wp db export --tables=$(wp db tables 'wp_user*' --format=csv)
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export all tables matching prefix
	 *     $ wp db export --tables=$(wp db tables --all-tables-with-prefix --format=csv)
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export certain posts without create table statements
	 *     $ wp db export --no-create-info=true --tables=wp_posts --where="ID in (100,101,102)"
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export relating meta for certain posts without create table statements
	 *     $ wp db export --no-create-info=true --tables=wp_postmeta --where="post_id in (100,101,102)"
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Skip certain tables from the exported database
	 *     $ wp db export --exclude_tables=wp_options,wp_users
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Skip all tables matching a wildcard from the exported database
	 *     $ wp db export --exclude_tables=$(wp db tables 'wp_user*' --format=csv)
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Skip all tables matching prefix from the exported database
	 *     $ wp db export --exclude_tables=$(wp db tables --all-tables-with-prefix --format=csv)
	 *     Success: Exported to 'wordpress_dbase-db72bb5.sql'.
	 *
	 *     # Export database to STDOUT.
	 *     $ wp db export -
	 *     -- MySQL dump 10.13  Distrib 5.7.19, for osx10.12 (x86_64)
	 *     --
	 *     -- Host: localhost    Database: wpdev
	 *     -- ------------------------------------------------------
	 *     -- Server version    5.7.19
	 *     ...
	 *
	 * @alias dump
	 */
	public function export( $args, $assoc_args ) {
		global $wpdb;

		if ( ! empty( $args[0] ) ) {
			$result_file = $args[0];
		} else {
			$hash = substr( md5( mt_rand() ), 0, 7 );
			$result_file = sprintf( '%s-%s-%s.sql', DB_NAME, date( 'Y-m-d' ), $hash );;
		}
		$stdout = ( '-' === $result_file );

		$files = array();
		$files[] = self::dump_structure();

		$files = array_merge( $files, self::dump_data() );

		self::join_files( $files, $result_file );

		self::cleanup();

		if ( ! $stdout ) {
			WP_CLI::success( sprintf( "Exported to '%s'. Export size: %s", $result_file, size_format( filesize( $result_file ) ) ) );
		}
	}

	protected static function get_temp_filename( $filename = null ) {
		if( $filename ) {
			return trailingslashit( sys_get_temp_dir() ) . $filename;
		} else {
			return tempnam(sys_get_temp_dir(), 'backup_export');
		}
	}

	public static function dump_structure() {
		$command = '/usr/bin/env mysqldump --no-defaults %s --single-transaction --quick';
		$command_esc_args = array( DB_NAME );

		$command .= ' --no-data';

		$escaped_command = call_user_func_array( '\WP_CLI\Utils\esc_cmd', array_merge( array( $command ), $command_esc_args ) );

		$first_pass = self::get_temp_filename();

		self::run( $escaped_command, array(
			'result-file' => $first_pass,
		) );

		return $first_pass;
	}

	public static function dump_data_from_table( $table, $filename = null ) {
		$command = '/usr/bin/env mysqldump --no-defaults %s --single-transaction --quick';
		$command_esc_args = array( DB_NAME );

		$command .= ' --no-create-info';

		$command .= ' --tables';
		$command .= ' %s';
		$command_esc_args[] = $table;

		$escaped_command = call_user_func_array( '\WP_CLI\Utils\esc_cmd', array_merge( array( $command ), $command_esc_args ) );
		
		$this_table_file = self::get_temp_filename( $filename );

		global $wpdb;

		self::run( $escaped_command, array(
			'result-file' => $this_table_file,
		) );

		WP_CLI::line( sprintf( 'Exported %d rows from %s. Export size: %s', $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ), $table, size_format( filesize( $this_table_file ) ) ) );

		return $this_table_file;
	}

	public static function get_tables_info() {
		global $wpdb;
		if(isset(self::$tables_info)) {
			return self::$tables_info;
		}

        $tables_to_custom_process = apply_filters( 'wp_local_maker_custom_process_tables', array() );

        $key=-1;
        foreach( $tables_to_custom_process as $table => $cb ) {
        	$key++;
        	$tables_to_custom_process[$table] = array(
				'prio' => $key,
				'callback' => $cb,
			);
        }

		$excluded_tables = apply_filters( 'wp_local_maker_excluded_tables', array() );

		foreach( $excluded_tables as $table ) {
			$key++;
			$tables_to_custom_process[$table] = array(
				'prio' => $key,
				'callback' => false,
			);
		}

		self::$tables_info = $tables_to_custom_process;
		return self::$tables_info;
	}

	public static function get_table_name( $table, $key = 'curr', $prefixed = true ) {
		global $wpdb;

		if( $prefixed ) {
			if( in_array( $table, self::global_tables() ) ) {
				$table = $wpdb->base_prefix . $table;
			} else {
				$table = $wpdb->prefix . $table;
			}
		}

		if( $key == 'temp' ) {
			return '_WPLM_' . $table . '_' . hash_hmac('crc32', $table, '123456');
		} else {
			return $table;
		}		
	}

	public static function get_tables_names() {
		$tables_info = self::get_tables_info();
		foreach($tables_info as $table => $info) {
			$new_info = array(
				'currname' => self::get_table_name( $table, 'curr' ),
				'tempname' => self::get_table_name( $table, 'temp' ),
			);
			$tables_info[$table] = $new_info;
		}
		return $tables_info;
	}

	public static function write_table_file( $table, $replace_name = '' ) {
		global $wpdb;
		$table_final_name = $table;
		if( $replace_name ) {
			$table_final_name = $replace_name;
		}

		$clean_table_name = $table_final_name;
		$clean_table_name = str_replace( $wpdb->prefix, '', $clean_table_name );
		$clean_table_name = str_replace( $wpdb->base_prefix, '', $clean_table_name );
		do_action( 'wp_local_maker_before_dump_' . $clean_table_name, $table );		

		$file = self::dump_data_from_table( $table, $table_final_name );

		if( $replace_name ) {
			$file = self::adjust_file( $file, "`{$table}`", "`{$replace_name}`" );
		}

		return $file;
	}

	public static function dependant_table_dump( $current_index, $after = '' ) {
		global $wpdb;
		$tables_info = self::get_tables_names();
		$current = $tables_info[ $current_index ][ 'currname' ];
		$temp = $tables_info[ $current_index ][ 'tempname' ];

		$wpdb->query("CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}");

		$query = "REPLACE INTO {$temp} SELECT * FROM {$current} p";
		if( $after ) {
			$query .= " " . $after;
		}

		$wpdb->query($query);

		$file = self::write_table_file( $temp, $current );

		return $file;
	}

	public static function dependant_table_dump_single( $current, $dependant, $current_key, $dependant_key ) {
		global $wpdb;
		$tables_info = self::get_tables_names();
		$temp_posts = $tables_info[ $dependant ][ 'tempname' ];
		return self::dependant_table_dump($current, "WHERE p.{$current_key} IN ( SELECT {$dependant_key} FROM {$temp_posts} p2 GROUP BY {$dependant_key} )");
	}

	public static function get_table_keys_group( $table, $prefix = '' ) {
		$keys = self::get_columns( $table )[0];
		if( $prefix ) {
			foreach($keys as $i => $key) {
				$keys[$i] = $prefix.'.'.$key;
			}
		}

		return implode(', ', $keys);
	}

	protected static function global_tables( ) {
		global $wpdb;
		return apply_filters( 'wp_local_maker_global_tables', $wpdb->tables( 'global', false ) );
	}

	protected static function dump_data() {
		global $wpdb;

		$files = array();
		$tables = $wpdb->get_col("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'");

		$tables_info = self::get_tables_info();
		$global_tables = self::global_tables();

		$process_queue = array();

		$global_queue = array();

		foreach($tables as $table) {
			$re = '/^' . preg_quote( $wpdb->base_prefix ). '(?:([0-9]*)_)?/m';
			preg_match($re, $table, $matches);

			$internal_key = $table;
			$blog_id = 1;
			$prefixed = true;
			if( !empty( $matches ) ) {
				// Print the entire match result
				if( ! isset($matches[1]) ) {
					$blog_id = 1;
				} else {
					$blog_id = (int) $matches[1];
				}
				$internal_key = substr($internal_key, strlen($matches[0]));
			} else {
				$prefixed = false;
			}

			if( ! isset( $tables_info[ $internal_key ] ) ) {
				$files[] = self::write_table_file( $table );
				continue;
			}

			$tbl_info = $tables_info[ $internal_key ];
			if( ! is_callable( $tbl_info['callback'] ) ) {
				continue;
			}

			$object_to_append = array(
				'currname' => $table,
				'tempname' => self::get_table_name( $internal_key, 'temp', $prefixed ),
				'callback' => $tbl_info['callback'],
			);

			if( in_array( $internal_key, $global_tables ) ) {
				$global_queue[ $tbl_info[ 'prio' ] ] = $object_to_append;
			} else {
				if( ! isset( $process_queue[ $blog_id ] ) ) {
					$process_queue[ $blog_id ] = array();
				}

				$process_queue[ $blog_id ][ $tbl_info[ 'prio' ] ] = $object_to_append;	
			}		
		}

		krsort( $process_queue, SORT_NUMERIC );

		if( ! empty( $global_queue ) ) {
			foreach( $process_queue as $blog_id => $queue ) {
				foreach( $global_queue as $prio => $tbl_info ) {
					$process_queue[ $blog_id ][ $prio ] = $tbl_info;
				}
			}
		}

		foreach( $process_queue as $blog_id => $blog_queue ) {
			ksort( $blog_queue, SORT_NUMERIC );
			$switched = false;
			if( is_multisite() && get_current_blog_id() != $blog_id ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}
			$process_queue[ $blog_id ] = $blog_queue;
			foreach( $blog_queue as $i => $tbl_info ) {
				$callback = $tbl_info[ 'callback' ];
				if( is_callable( $callback ) ) {
					$files[] = call_user_func( $callback, $tbl_info['currname'], $tbl_info['tempname'] );
					unset($process_queue[$blog_id][$i]);
				}
			}
			if( $switched ) {
				restore_current_blog();
			}

			if( empty( $process_queue[$blog_id] ) ) {
				unset( $process_queue[$blog_id] );
			}
		} 

		if( ! empty( $process_queue ) ) {
			WP_CLI::warning( sprintf( "Unfinished tables %s.", implode( ', ', $process_queue ) ) );
		}

		return $files;
	}

	public static function adjust_file( $file, $find, $replace ) {
		$lines = [];
		$source = fopen($file, "r");
		$target_name = self::get_temp_filename();
		$target = fopen($target_name, 'w');

		while(!feof($source)) {
			$str = str_replace($find, $replace, fgets($source));
			fputs($target, $str);
		}

		fclose($source);
		@unlink($file);
		fclose($target);
		return $target_name;
	}

	protected static function join_files($files, $result_file) {
		@unlink($result_file);
		$target = fopen($result_file, "w");

		foreach($files as $file) {
			$source = fopen($file, "r");
			stream_copy_to_stream($source, $target);
			fclose($source);
			@unlink($file);
		}

		fclose($target);
	}

	protected static function cleanup() {
		global $wpdb;
		$tables_info = self::get_tables_names();
		foreach($tables_info as $table => $info) {
			$temp = $info['tempname'];
			$wpdb->query("DROP TABLE IF EXISTS {$temp}");
		}
	}

	/**
	 * Imports a database from a file or from STDIN.
	 *
	 * Runs SQL queries using `DB_HOST`, `DB_NAME`, `DB_USER` and
	 * `DB_PASSWORD` database credentials specified in wp-config.php. This
	 * does not create database by itself and only performs whatever tasks are
	 * defined in the SQL.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : The name of the SQL file to import. If '-', then reads from STDIN. If omitted, it will look for '{dbname}.sql'.
	 *
	 * [--dbuser=<value>]
	 * : Username to pass to mysql. Defaults to DB_USER.
	 *
	 * [--dbpass=<value>]
	 * : Password to pass to mysql. Defaults to DB_PASSWORD.
	 *
	 * [--skip-optimization]
	 * : When using an SQL file, do not include speed optimization such as disabling auto-commit and key checks.
	 *
	 * ## EXAMPLES
	 *
	 *     # Import MySQL from a file.
	 *     $ wp db import wordpress_dbase.sql
	 *     Success: Imported from 'wordpress_dbase.sql'.
	 */
	public function import( $args, $assoc_args ) {
		if ( ! empty( $args[0] ) ) {
			$result_file = $args[0];
		} else {
			$result_file = sprintf( '%s.sql', DB_NAME );
		}

		$mysql_args = array(
			'database' => DB_NAME,
		);
		$mysql_args = array_merge( self::get_dbuser_dbpass_args( $assoc_args ), $mysql_args );

		if ( '-' !== $result_file ) {
			if ( ! is_readable( $result_file ) ) {
				WP_CLI::error( sprintf( 'Import file missing or not readable: %s', $result_file ) );
			}

			$query = \WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-optimization' )
				? 'SOURCE %s;'
				: 'SET autocommit = 0; SET unique_checks = 0; SET foreign_key_checks = 0; SOURCE %s; COMMIT;';

			$mysql_args['execute'] = sprintf( $query, $result_file );
		}

		self::run( '/usr/bin/env mysql --no-defaults --no-auto-rehash', $mysql_args );

		WP_CLI::success( sprintf( "Imported from '%s'.", $result_file ) );
	}

	private static function run( $cmd, $assoc_args = array(), $descriptors = null ) {
		$required = array(
			'host' => DB_HOST,
			'user' => DB_USER,
			'pass' => DB_PASSWORD,
		);

		if ( ! isset( $assoc_args['default-character-set'] )
			&& defined( 'DB_CHARSET' ) && constant( 'DB_CHARSET' ) ) {
			$required['default-character-set'] = constant( 'DB_CHARSET' );
		}

		// Using 'dbuser' as option name to workaround clash with WP-CLI's global WP 'user' parameter, with 'dbpass' also available for tidyness.
		if ( isset( $assoc_args['dbuser'] ) ) {
			$required['user'] = $assoc_args['dbuser'];
			unset( $assoc_args['dbuser'] );
		}
		if ( isset( $assoc_args['dbpass'] ) ) {
			$required['pass'] = $assoc_args['dbpass'];
			unset( $assoc_args['dbpass'], $assoc_args['password'] );
		}

		$final_args = array_merge( $assoc_args, $required );
		Utils\run_mysql_command( $cmd, $final_args, $descriptors );
	}

	/**
	 * Helper to pluck 'dbuser' and 'dbpass' from associative args array.
	 *
	 * @param array $assoc_args Associative args array.
	 * @return array Array with `dbuser' and 'dbpass' set if in passed-in associative args array.
	 */
	private static function get_dbuser_dbpass_args( $assoc_args ) {
		$mysql_args = array();
		if ( null !== ( $dbuser = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dbuser' ) ) ) {
			$mysql_args['dbuser'] = $dbuser;
		}
		if ( null !== ( $dbpass = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dbpass' ) ) ) {
			$mysql_args['dbpass'] = $dbpass;
		}
		return $mysql_args;
	}

	/**
	 * Gets the column names of a db table differentiated into key columns and text columns and all columns.
	 *
	 * @param string $table The table name.
	 * @return array A 3 element array consisting of an array of primary key column names, an array of text column names, and an array containing all column names.
	 */
	private static function get_columns( $table ) {
		global $wpdb;

		$table_sql = self::esc_sql_ident( $table );
		$primary_keys = $text_columns = $all_columns = array();
		$suppress_errors = $wpdb->suppress_errors();
		if ( ( $results = $wpdb->get_results( "DESCRIBE $table_sql" ) ) ) {
			foreach ( $results as $col ) {
				if ( 'PRI' === $col->Key ) {
					$primary_keys[] = $col->Field;
				}
				if ( self::is_text_col( $col->Type ) ) {
					$text_columns[] = $col->Field;
				}
				$all_columns[] = $col->Field;
			}
		}
		$wpdb->suppress_errors( $suppress_errors );
		return array( $primary_keys, $text_columns, $all_columns );
	}

	/**
	 * Determines whether a column is considered text or not.
	 *
	 * @param string Column type.
	 * @bool True if text column, false otherwise.
	 */
	private static function is_text_col( $type ) {
		foreach ( array( 'text', 'varchar' ) as $token ) {
			if ( false !== strpos( $type, $token ) )
				return true;
		}

		return false;
	}

	/**
	 * Escapes (backticks) MySQL identifiers (aka schema object names) - i.e. column names, table names, and database/index/alias/view etc names.
	 * See https://dev.mysql.com/doc/refman/5.5/en/identifiers.html
	 *
	 * @param string|array $idents A single identifier or an array of identifiers.
	 * @return string|array An escaped string if given a string, or an array of escaped strings if given an array of strings.
	 */
	private static function esc_sql_ident( $idents ) {
		$backtick = function ( $v ) {
			// Escape any backticks in the identifier by doubling.
			return '`' . str_replace( '`', '``', $v ) . '`';
		};
		if ( is_string( $idents ) ) {
			return $backtick( $idents );
		}
		return array_map( $backtick, $idents );
	}
}

WP_CLI::add_command( 'backup', 'Backup_Command' );

class WP_LMaker_Addon {
	function __construct() {
		add_filter( 'wp_local_maker_excluded_tables', array( $this, 'excluded_tables' ) );
	}
	function excluded_tables( $tables ) {
		return $tables;
	}
	protected function is_plugin_active( $plugin ) {
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		return is_plugin_active( $plugin );
	}
}

class WP_LMaker_Core {
    function __construct() {
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_posts' ), 10);
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_comments' ), 20);
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_users' ), 30);
        add_action( 'wp_local_maker_before_dump_usermeta', array( $this, 'before_dump_usermeta' ) );
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_terms' ), 40);
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_core' ), 50);
    }
    function enqueue_process_posts( $tables ) {
    	global $wpdb;
        $tables['posts'] = array( $this, 'process_posts' );
        $tables['postmeta'] = array( $this, 'process_postmeta' );
        return $tables;
    }

    function enqueue_process_comments( $tables ) {
    	global $wpdb;
        $tables['comments'] = array( $this, 'process_comments' );
        $tables['commentmeta'] = array( $this, 'process_commentmeta' );
        return $tables;
    }

    function enqueue_process_users( $tables ) {
    	global $wpdb;
        $tables['users'] = array( $this, 'process_users' );
        $tables['usermeta'] = array( $this, 'process_usermeta' );
        return $tables;
    }

    function enqueue_process_terms( $tables ) {
    	global $wpdb;
        $tables['term_relationships'] = array( $this, 'process_term_relationships' );
        $tables['term_taxonomy'] = array( $this, 'process_term_taxonomy' );
        $tables['terms'] = array( $this, 'process_terms' );
        $tables['termmeta'] = array( $this, 'process_termmeta' );
        return $tables;
    }

    function enqueue_process_core( $tables ) {
    	global $wpdb;
        $tables['options'] = array( $this, 'process_options' );
        return $tables;
    }

	public function process_posts() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];

		$wpdb->query("CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}");

		$ignored_post_types = apply_filters( 'wp_local_maker_ignore_straight_post_types', array( 'post', 'attachment', 'revision' ) );
		foreach( $ignored_post_types as $k => $v ) {
			$ignored_post_types[$k] = $wpdb->prepare('%s', $v);
		}
		$ignored_post_types = implode(',', $ignored_post_types);

		// Export everything but a few known post types
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type NOT IN ( {$ignored_post_types} )");

		// Handle posts
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'post' )
			ORDER BY p.post_date DESC
			LIMIT 50");

		do_action( 'wp_local_maker_posts_after_posts', $tables_info );

		// Handle attachments
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'attachment' ) AND p.post_parent IN ( SELECT ID FROM {$temp} p2 )");

		// Handle unrelated attachments
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'attachment' ) AND p.post_parent = 0
            ORDER BY p.post_date DESC
			LIMIT 500");

        // Loop until there's no missing parents
        do {
            $affected = $wpdb->query("REPLACE INTO {$temp}
                SELECT p3.* FROM {$temp} p
                LEFT JOIN {$temp} p2 ON p2.ID = p.post_parent
                LEFT JOIN {$current} p3 ON p3.ID = p.post_parent
                WHERE p.post_parent != 0 AND p2.ID IS NULL AND p3.ID IS NOT NULL");
        } while( $affected > 0 );

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function process_postmeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('postmeta', 'posts', 'post_id', 'ID');
	}

	public function process_comments() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('comments', 'posts', 'comment_post_ID', 'ID');
	}

	public function process_commentmeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('commentmeta', 'comments', 'comment_id', 'comment_ID');
	}

	public function process_users() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current = $tables_info[ 'users' ][ 'currname' ];
		$temp = $tables_info[ 'users' ][ 'tempname' ];
		$curr_usermeta = $tables_info[ 'usermeta' ][ 'currname' ];

		$wpdb->query("CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}");

		// Export administrators
		$wpdb->query("REPLACE INTO {$temp}
			SELECT u.* FROM {$current} u 
			INNER JOIN {$curr_usermeta} um ON um.user_id = u.ID AND um.meta_key = 'wp_capabilities'
			WHERE um.meta_value LIKE '%\"administrator\"%'");

		do_action( 'wp_local_maker_users_after_admins', $tables_info, $current, $temp );

		$temp_posts = $tables_info[ 'posts' ][ 'tempname' ];

		$user_keys = Backup_Command::get_table_keys_group( $current, 'u' );

		// Export authors
		$wpdb->query("REPLACE INTO {$temp}
			SELECT u.* FROM {$current} u 
			INNER JOIN {$temp_posts} p ON p.post_author = u.ID
			GROUP BY {$user_keys}");

		do_action( 'wp_local_maker_users_after_authors', $tables_info, $current, $temp );     

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function process_usermeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('usermeta', 'users', 'user_id', 'ID');
	}

	public function before_dump_usermeta( $table_name ) {
    	global $wpdb;

    	$wpdb->query("DELETE FROM {$table_name}
    		WHERE meta_key = 'session_tokens'");
    }

	public function process_term_relationships() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current = $tables_info[ 'term_relationships' ][ 'currname' ];
		$temp = $tables_info[ 'term_relationships' ][ 'tempname' ];

		$wpdb->query("CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}");

		$temp_posts = $tables_info[ 'posts' ][ 'tempname' ];

		$tr_keys = Backup_Command::get_table_keys_group( $current, 'tr' );

		// Export post terms
		$wpdb->query("REPLACE INTO {$temp}
			SELECT tr.* FROM {$current} tr
			INNER JOIN {$temp_posts} p ON tr.object_id = p.ID
			GROUP BY {$tr_keys}");

		$temp_users = $tables_info[ 'users' ][ 'tempname' ];

		// Export potential author terms
		$wpdb->query("REPLACE INTO {$temp}
			SELECT tr.* FROM {$current} tr
			INNER JOIN {$temp_users} u ON tr.object_id = u.ID
			GROUP BY {$tr_keys}");

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}

	public function process_term_taxonomy() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('term_taxonomy', 'term_relationships', 'term_taxonomy_id', 'term_taxonomy_id');
	}

	public function process_terms() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('terms', 'term_taxonomy', 'term_id', 'term_id');
	}

	public function process_termmeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('termmeta', 'terms', 'term_id', 'term_id');
	}

	public function process_options() {
		global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current = $tables_info[ 'options' ][ 'currname' ];
		$temp = $tables_info[ 'options' ][ 'tempname' ];

		$wpdb->query("CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}");

		// Exclude transients
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current}
			WHERE option_name NOT LIKE '\_transient%' && option_name NOT LIKE '\_site\_transient%'");

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
	}
}
new WP_LMaker_Core();

class WP_LMaker_WooCommerce extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_order_items' ), 25);
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_download_permissions' ), 27);
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_payment_tokens' ), 35);
        add_action( 'wp_local_maker_users_after_authors', array($this, 'process_customers'));
        add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
        add_action( 'wp_local_maker_posts_after_posts', array( $this, 'process_orders' ) );
        add_action( 'wp_local_maker_posts_after_posts', array( $this, 'process_coupons' ) );
        add_action( 'wp_local_maker_posts_after_posts', array( $this, 'process_products' ) );
    }

    function ignore_straight_post_types($types) {
    	$types[] = 'shop_order';
    	$types[] = 'shop_order_refund';
    	$types[] = 'shop_coupon';
    	$types[] = 'product';
    	return $types;
    }

    function enqueue_process_order_items( $tables ) {
    	global $wpdb;
        $tables['woocommerce_order_items'] = array( $this, 'process_woocommerce_order_items' );
        $tables['woocommerce_order_itemmeta'] = array( $this, 'process_woocommerce_order_itemmeta' );
        return $tables;
    }

    function enqueue_process_download_permissions( $tables ) {
    	global $wpdb;
        $tables['woocommerce_downloadable_product_permissions'] = array( $this, 'process_woocommerce_downloadable_product_permissions' );
        return $tables;
    }

    function enqueue_process_payment_tokens( $tables ) {
    	global $wpdb;
        $tables['woocommerce_payment_tokens'] = array( $this, 'process_woocommerce_payment_tokens' );
        $tables['woocommerce_payment_tokenmeta'] = array( $this, 'process_woocommerce_payment_tokenmeta' );
        return $tables;
    }

	public function process_woocommerce_order_items() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('woocommerce_order_items', 'posts', 'order_id', 'ID');
	}

	public function process_woocommerce_order_itemmeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('woocommerce_order_itemmeta', 'woocommerce_order_items', 'order_item_id', 'order_item_id');
	}

	public function process_woocommerce_downloadable_product_permissions() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('woocommerce_downloadable_product_permissions', 'posts', 'order_id', 'ID');
	}

	public function process_woocommerce_payment_tokens() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('woocommerce_payment_tokens', 'users', 'user_id', 'ID');
	}

	public function process_woocommerce_payment_tokenmeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('woocommerce_payment_tokenmeta', 'woocommerce_payment_tokens', 'payment_token_id', 'token_id');
	}

	public function process_orders( $tables_info ){
    	global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];

		// Handle orders
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'shop_order' )
			ORDER BY p.post_date DESC
			LIMIT 50");

		do_action( 'wp_local_maker_orders_after_orders', $tables_info );

		// Handle refunds
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'shop_order_refund' ) AND p.post_parent IN ( SELECT ID FROM {$temp} p2 )");
	}

	public function process_coupons( $tables_info ){
    	global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];
		$curr_oi = $tables_info[ 'woocommerce_order_items' ][ 'currname' ];

		// Handle coupons (only copy used)
		$wpdb->query("CREATE TEMPORARY TABLE wp_list_temp 
			SELECT oi.order_item_name FROM {$curr_oi} oi
			WHERE oi.order_id IN ( SELECT ID FROM {$temp} ) AND oi.order_item_type = 'coupon'
			GROUP BY oi.order_item_name");

		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'shop_coupon' ) AND post_title IN ( SELECT * FROM wp_list_temp )");

		$wpdb->query("DROP TABLE wp_list_temp");
	}

	public function process_products( $tables_info ){
    	global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];

		// Handle products
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM {$current} p
			WHERE p.post_status NOT IN ('auto-draft', 'trash')
			AND p.post_type IN ( 'product' )");
	}

	public function process_customers( $tables_info ) {
    	global $wpdb;
		$current = $tables_info[ 'users' ][ 'currname' ];
		$temp = $tables_info[ 'users' ][ 'tempname' ];
		$temp_posts = $tables_info[ 'posts' ][ 'tempname' ];
		$temp_postmeta = $tables_info[ 'postmeta' ][ 'tempname' ];
		$user_keys = Backup_Command::get_table_keys_group( $current, 'u' );

		// Export customers
		$wpdb->query("REPLACE INTO {$temp}
			SELECT u.* FROM {$temp_posts} p 
			INNER JOIN {$temp_postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_customer_user'
			INNER JOIN {$current} u ON u.ID = pm.meta_value
			GROUP BY {$user_keys}");   
	}

	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'wc_download_log';
		$tables[] = 'woocommerce_sessions';
		$tables[] = 'woocommerce_log';
		return $tables;
	}
}
new WP_LMaker_WooCommerce();

class WP_LMaker_WooCommerce_Subscriptions extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
        add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
        add_action( 'wp_local_maker_orders_after_orders', array( $this, 'process_subscriptions' ) );
    }

    function process_subscriptions( $tables_info ) {
    	global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];
		$curr_pm = $tables_info[ 'postmeta' ][ 'currname' ];

    	// Handle subscriptions
        $wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$current} p
            WHERE p.post_status NOT IN ('auto-draft', 'trash')
            AND p.post_type IN ( 'shop_subscription' )
            ORDER BY p.post_date DESC
            LIMIT 50");

        // Handle subscriptions related orders
        $wpdb->query("REPLACE INTO {$temp}
            SELECT p.* FROM {$current} p
            INNER JOIN {$curr_pm} pm ON p.ID = pm.post_id AND ( pm.meta_key = '_subscription_switch' OR pm.meta_key = '_subscription_renewal' OR pm.meta_key = 'subscription_resubscribe' )
            WHERE pm.meta_value IN ( SELECT ID FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )");

        do_action( 'wp_local_maker_subscriptions_after_subscriptions', $tables_info );
    }

    function ignore_straight_post_types($types) {
    	$types[] = 'shop_subscription';
    	return $types;
    }
}
new WP_LMaker_WooCommerce_Subscriptions();

class WP_LMaker_WooCommerce_Memberships extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
        add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
        add_action( 'wp_local_maker_orders_after_orders', array( $this, 'process_memberships' ) );
    }

    function process_memberships( $tables_info ) {
    	global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];
		$curr_pm = $tables_info[ 'postmeta' ][ 'currname' ];

        // Handle memberships
        $wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$current} p
            WHERE p.post_status NOT IN ('auto-draft', 'trash')
            AND p.post_type IN ( 'wc_user_membership' )
            ORDER BY p.post_date DESC
            LIMIT 50");

        // Handle subscriptions related memberships
        $wpdb->query("REPLACE INTO {$temp}
            SELECT p.* FROM {$current} p
            INNER JOIN {$curr_pm} pm ON p.ID = pm.post_id AND pm.meta_key = '_subscription_id'
            WHERE p.post_type IN ( 'wc_user_membership' ) AND pm.meta_value IN ( SELECT ID FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )");

        do_action( 'wp_local_maker_memberships_after_memberships', $tables_info );
    }

    function ignore_straight_post_types($types) {
    	$types[] = 'wc_user_membership';
    	return $types;
    }
}
new WP_LMaker_WooCommerce_Memberships();

class WP_LMaker_Action_Scheduler extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
        add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
        add_action( 'wp_local_maker_subscriptions_after_subscriptions', array( $this, 'process_subscriptions_actions' ) );
        add_action( 'wp_local_maker_memberships_after_memberships', array( $this, 'process_memberships_actions' ) );
    }

    function process_subscriptions_actions( $tables_info ) {
    	global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];

        // Handle subscriptions related actions
        $wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE post_type = 'scheduled-action' AND post_content IN ( SELECT CONCAT('{\"subscription_id\":', ID, '}') FROM {$temp} p2 WHERE p2.post_type = 'shop_subscription' )");
    }

    function process_memberships_actions( $tables_info ) {
    	global $wpdb;
		$current = $tables_info[ 'posts' ][ 'currname' ];
		$temp = $tables_info[ 'posts' ][ 'tempname' ];

        // Handle memberships related actions
        $wpdb->query("REPLACE INTO {$temp}
            SELECT * FROM {$current} 
            WHERE post_type = 'scheduled-action' AND post_content IN ( SELECT CONCAT('{\"user_membership_id\":', ID, '}') FROM {$temp} p2 WHERE p2.post_type = 'wc_user_membership' )");
    }

    function ignore_straight_post_types($types) {
    	$types[] = 'scheduled-action';
    	return $types;
    }
}
new WP_LMaker_Action_Scheduler();

class WP_LMaker_Gravity_Forms extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'gf_entry';
		$tables[] = 'gf_entry_meta';
		$tables[] = 'gf_entry_notes';
		$tables[] = 'gf_form_view';
		$tables[] = 'rg_lead';
		$tables[] = 'rg_lead_detail';
		$tables[] = 'rg_lead_detail_long';
		$tables[] = 'rg_lead_meta';
		$tables[] = 'rg_lead_notes';
		$tables[] = 'rg_form_view';
		$tables[] = 'rg_incomplete_submissions';
		return $tables;
	}
}
new WP_LMaker_Gravity_Forms();

class WP_LMaker_Redirection extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'redirection_logs';
		$tables[] = 'redirection_404';
		return $tables;
	}
}
new WP_LMaker_Redirection();

class WP_LMaker_SCH_Smart_Transients extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'sch_smart_transients';
		return $tables;
	}
}
new WP_LMaker_SCH_Smart_Transients();

class WP_LMaker_Affiliate_WP extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'affiliate_wp_visits';
		return $tables;
	}
}
new WP_LMaker_Affiliate_WP();

class WP_LMaker_Abandoned_Carts_Pro extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'ac_abandoned_cart_history';
		$tables[] = 'ac_guest_abandoned_cart_history';
		return $tables;
	}
}
new WP_LMaker_Abandoned_Carts_Pro();

class WP_LMaker_Order_Generator extends WP_LMaker_Addon {
	function excluded_tables( $tables ) {
		global $wpdb;
		$tables[] = 'fakenames';
		return $tables;
	}
}
new WP_LMaker_Order_Generator();

class WP_LMaker_EWWWIO extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_ewwio' ), 45);
    }

    function enqueue_process_ewwio( $tables ) {
    	global $wpdb;
        $tables['ewwwio_images'] = array( $this, 'process_ewwwio_images' );
        return $tables;
    }

	public function process_ewwwio_images() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('ewwwio_images', 'posts', 'id', 'ID');
	}
}
new WP_LMaker_EWWWIO();

class WP_LMaker_NGG extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_ngg' ), 45);
        add_filter( 'wp_local_maker_ignore_straight_post_types', array( $this, 'ignore_straight_post_types' ) );
    }

    function enqueue_process_ngg( $tables ) {
    	global $wpdb;
    	if( ! $this->is_plugin_active( 'nextgen-gallery/nggallery.php' ) ) {
    		$tables['ngg_album'] = false;
    		$tables['ngg_gallery'] = false;
    		$tables['ngg_pictures'] = false;
    	}
        return $tables;
    }

    function ignore_straight_post_types($types) {
    	if( ! $this->is_plugin_active( 'nextgen-gallery/nggallery.php' ) ) {
    		$types[] = 'ngg_pictures';
    	}
    	return $types;
    }
}
new WP_LMaker_NGG();

class WP_LMaker_SCR extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_scr' ), 45);
        add_filter( 'wp_local_maker_global_tables', array( $this, 'register_global_tables' ), 45);
    }

    function enqueue_process_scr( $tables ) {
    	global $wpdb;
        $tables['scr_relationships'] = array( $this, 'process_scr_relationships' );
        $tables['scr_relationshipmeta'] = array( $this, 'process_scr_relationshipmeta' );
        return $tables;
    }

    function register_global_tables( $tables ) {
    	$tables[] = 'scr_relationships';
    	$tables[] = 'scr_relationshipmeta';
    	return $tables;
    }

    function process_scr_relationships() {
    	global $wpdb;
		$tables_info = Backup_Command::get_tables_names();
		$current = $tables_info[ 'scr_relationships' ][ 'currname' ];
		$temp = $tables_info[ 'scr_relationships' ][ 'tempname' ];

		$wpdb->query("CREATE TABLE IF NOT EXISTS {$temp} LIKE {$current}");

		$temp_posts = $tables_info[ 'posts' ][ 'tempname' ];
		$temp_users = $tables_info[ 'users' ][ 'tempname' ];

		// Export every matching relationship from a user standpoint
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM wp_scr_relationships scr 
			WHERE 
			scr.object1_type = 'user' AND scr.object1_site = 1 AND scr.object1_id IN ( SELECT ID FROM {$temp_users} )  
			AND
			scr.object2_type = 'post' AND scr.object2_site = " . get_current_blog_id() . " AND scr.object2_id IN ( SELECT ID FROM {$temp_posts} )");

		// Export every matching relationship from a post standpoint
		$wpdb->query("REPLACE INTO {$temp}
			SELECT * FROM wp_scr_relationships scr 
			WHERE 
			scr.object1_type = 'post' AND scr.object1_site = " . get_current_blog_id() . " AND scr.object1_id IN ( SELECT ID FROM {$temp_posts} )
			AND
			scr.object2_type = 'user' AND scr.object2_site = 1 AND scr.object2_id IN ( SELECT ID FROM {$temp_users} )");

		$file = Backup_Command::write_table_file( $temp, $current );

		return $file;
    }	

	public function process_scr_relationshipmeta() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('scr_relationshipmeta', 'scr_relationships', 'scr_relationship_id', 'rel_id');
	}
}
new WP_LMaker_SCR();

class WP_LMaker_WooCommerce_Order_Index extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_customer_order_index' ), 45);
    }

    function enqueue_process_customer_order_index( $tables ) {
    	global $wpdb;
        $tables['woocommerce_customer_order_index'] = array( $this, 'process_customer_order_index' );
        return $tables;
    }

	public function process_customer_order_index() {
		global $wpdb;
		return Backup_Command::dependant_table_dump_single('woocommerce_customer_order_index', 'posts', 'order_id', 'ID');
	}
}
new WP_LMaker_WooCommerce_Order_Index();

class WP_LMaker_SAD extends WP_LMaker_Addon {
	function __construct() {
		parent::__construct();
        add_filter( 'wp_local_maker_custom_process_tables', array( $this, 'enqueue_process_sad_users' ), 45);
    }

    function enqueue_process_sad_users( $tables ) {
    	global $wpdb;
    	if( ! $this->is_plugin_active( 'halfdata-optin-downloads/halfdata-optin-downloads.php' ) ) {
    		$tables['sad_users'] = false;
    	}
        return $tables;
    }
}
new WP_LMaker_SAD();
