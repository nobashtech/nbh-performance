<?php
/**
 * Verified native SQL database backup exporter.
 *
 * Uses a repeatable-read snapshot, deterministic table ordering, binary-safe
 * values, free-space preflight and an atomic verified artifact manifest.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\DatabaseOptimization;

use NBH\Performance\Modules\Core\DataOwnershipRegistry;
use WP_Error;

final class NativeSqlBackup {
	const FORMAT_VERSION = 2;
	const BATCH_SIZE     = 250;

	/**
	 * Create a verified SQL artifact.
	 *
	 * @param string $backup_id Backup record ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create( $backup_id ) {
		global $wpdb;

		$directory = $this->directory();
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		$objects = $this->database_objects();
		if ( is_wp_error( $objects ) ) {
			return $objects;
		}
		if ( empty( $objects['tables'] ) ) {
			return new WP_Error( 'nbh_database_backup_no_tables', __( 'No WordPress database tables were available to back up.', 'nbh-performance' ) );
		}

		$unsafe = array();
		foreach ( $objects['tables'] as $table ) {
			$engine = strtoupper( (string) $table['engine'] );
			if ( '' !== $engine && 'INNODB' !== $engine ) {
				$unsafe[] = $table['name'] . ' (' . $engine . ')';
			}
		}
		if ( $unsafe && ! apply_filters( 'nbh_performance_database_backup_allow_nontransactional', false, $unsafe ) ) {
			return new WP_Error(
				'nbh_database_backup_nontransactional_tables',
				sprintf(
					/* translators: %s: comma-separated table names. */
					__( 'A transaction-consistent backup cannot be guaranteed because these tables are not InnoDB: %s', 'nbh-performance' ),
					implode( ', ', array_slice( $unsafe, 0, 10 ) )
				)
			);
		}

		$unordered = array();
		foreach ( $objects['tables'] as $table ) {
			if ( ! $this->order_columns( $table['name'] ) ) {
				$unordered[] = $table['name'];
			}
		}
		if ( $unordered && ! apply_filters( 'nbh_performance_database_backup_allow_unordered_tables', false, $unordered ) ) {
			return new WP_Error(
				'nbh_database_backup_unordered_tables',
				sprintf(
					/* translators: %s: comma-separated table names. */
					__( 'A deterministic backup cannot be guaranteed because these tables do not have a primary or unique index: %s', 'nbh-performance' ),
					implode( ', ', array_slice( $unordered, 0, 10 ) )
				)
			);
		}

		$estimated = $this->estimated_database_bytes( $objects['tables'] );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output.
		$free     = @disk_free_space( $directory );
		$required = max( 128 * MB_IN_BYTES, (int) ceil( $estimated * 1.35 ) );
		if ( false !== $free && $free < $required ) {
			return new WP_Error(
				'nbh_database_backup_insufficient_disk',
				__( 'The database backup was stopped because there is not enough free disk space for a verified snapshot.', 'nbh-performance' )
			);
		}

		try {
			$token = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			$token = substr( wp_generate_password( 32, false, false ), 0, 32 );
		}
		$filename = sanitize_file_name( $backup_id . '-' . gmdate( 'Ymd-His' ) . '-' . $token . '.sql' );
		$path     = trailingslashit( $directory ) . $filename;
		$temp     = $path . '.part';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output. A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		$handle = @fopen( $temp, 'xb' );
		if ( ! $handle ) {
			return new WP_Error( 'nbh_database_backup_open_failed', __( 'The database backup file could not be created.', 'nbh-performance' ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $temp, 0600 );

		$rows        = 0;
		$table_count = 0;
		$view_count  = 0;
		$transaction = false;

		try {
			$this->write( $handle, "-- NBH Performance verified database backup\n" );
			$this->write( $handle, '-- Format version: ' . self::FORMAT_VERSION . "\n" );
			$this->write( $handle, '-- Build: ' . ( defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '' ) . "\n" );
			$this->write( $handle, '-- Created UTC: ' . gmdate( 'c' ) . "\n" );
			$this->write( $handle, "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET FOREIGN_KEY_CHECKS=0;\n\n" );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
			if ( false === $wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) {
				throw new \RuntimeException( esc_html__( 'The database server could not start a consistent backup snapshot.', 'nbh-performance' ) );
			}
			$transaction = true;

			foreach ( $objects['tables'] as $table ) {
				$name       = $table['name'];
				$identifier = $this->identifier( $name );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $name ), ARRAY_N );
				if ( ! is_array( $create ) || empty( $create[1] ) ) {
					/* translators: %s: database table name. */
					throw new \RuntimeException( sprintf( __( 'Could not read the schema for table %s.', 'nbh-performance' ), $name ) );
				}

				$this->write( $handle, '-- Table: ' . $identifier . "\nDROP TABLE IF EXISTS " . $identifier . ";\n" . $create[1] . ";\n\n" );
				$columns            = $this->column_types( $name );
				$order_columns      = $this->order_columns( $name );
				$order_placeholders = implode( ',', array_fill( 0, count( $order_columns ), '%i' ) );
				$order              = $order_columns ? ' ORDER BY ' . $order_placeholders : '';
				$offset             = 0;

				do {
					$sql  = 'SELECT * FROM %i' . $order . ' LIMIT %d OFFSET %d';
					$args = array_merge( array( $name ), $order_columns, array( self::BATCH_SIZE, $offset ) );
					// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here. ORDER BY contains only generated %i placeholders; schema-derived identifiers, table and numeric pagination are bound in the argument array. A verified fixture export exercises this path.
					$data = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
					if ( null === $data ) {
						/* translators: %s: database table name. */
						throw new \RuntimeException( sprintf( __( 'Could not read rows from table %s.', 'nbh-performance' ), $name ) );
					}
					if ( empty( $data ) ) {
						break;
					}

					$column_names = array_keys( $data[0] );
					$column_sql   = implode( ',', array_map( array( $this, 'identifier' ), $column_names ) );
					$values       = array();
					foreach ( $data as $row ) {
						$row_values = array();
						foreach ( $column_names as $column ) {
							$row_values[] = $this->sql_value( $row[ $column ], isset( $columns[ $column ] ) ? $columns[ $column ] : '' );
						}
						$values[] = '(' . implode( ',', $row_values ) . ')';
					}
					$this->write( $handle, 'INSERT INTO ' . $identifier . ' (' . $column_sql . ") VALUES\n" . implode( ",\n", $values ) . ";\n\n" );
					$rows     += count( $data );
					$offset   += self::BATCH_SIZE;
					$row_count = count( $data );
				} while ( self::BATCH_SIZE === $row_count );
				++$table_count;
			}

			foreach ( $objects['views'] as $view ) {
				$name       = $view['name'];
				$identifier = $this->identifier( $name );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
				$create = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE VIEW %i', $name ), ARRAY_N );
				if ( ! is_array( $create ) || empty( $create[1] ) ) {
					/* translators: %s: database view name. */
					throw new \RuntimeException( sprintf( __( 'Could not read the definition for view %s.', 'nbh-performance' ), $name ) );
				}
				$this->write( $handle, '-- View: ' . $identifier . "\nDROP VIEW IF EXISTS " . $identifier . ";\n" . $create[1] . ";\n\n" );
				++$view_count;
			}

			$this->dump_triggers( $handle, $objects['tables'] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new \RuntimeException( esc_html__( 'The database backup snapshot could not be finalized.', 'nbh-performance' ) );
			}
			$transaction = false;

			$summary = array(
				'format_version' => self::FORMAT_VERSION,
				'tables'         => $table_count,
				'views'          => $view_count,
				'rows'           => $rows,
				'completed_at'   => time(),
			);
			$this->write( $handle, "SET FOREIGN_KEY_CHECKS=1;\n" );
			$this->write( $handle, '-- NBH-BACKUP-SUMMARY ' . wp_json_encode( $summary, JSON_UNESCAPED_SLASHES ) . "\n" );
			$this->write( $handle, "-- NBH-BACKUP-COMPLETE\n" );
			fflush( $handle );
			if ( function_exists( 'fsync' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@fsync( $handle );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
			fclose( $handle );
			$handle = null;
		} catch ( \Throwable $error ) {
			if ( $transaction ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
				$wpdb->query( 'ROLLBACK' );
			}
			if ( is_resource( $handle ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
				fclose( $handle );
			}
			wp_delete_file( $temp );
			return new WP_Error( 'nbh_database_backup_failed', $error->getMessage() );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! $this->verify_readable( $temp ) || ! @rename( $temp, $path ) ) {
			wp_delete_file( $temp );
			return new WP_Error( 'nbh_database_backup_verify_failed', __( 'The database backup artifact failed integrity verification.', 'nbh-performance' ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $path, 0600 );

		$size          = (int) filesize( $path );
		$hash          = hash_file( 'sha256', $path );
		$manifest_path = $path . '.manifest.json';
		$manifest_temp = $manifest_path . '.part';
		$manifest      = array(
			'format_version' => self::FORMAT_VERSION,
			'filename'       => basename( $path ),
			'sha256'         => $hash,
			'size_bytes'     => $size,
			'tables'         => $table_count,
			'views'          => $view_count,
			'rows'           => $rows,
			'created_at'     => time(),
			'build'          => defined( 'NBH_PERFORMANCE_BUILD' ) ? NBH_PERFORMANCE_BUILD : '',
		);
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename
		if ( false === file_put_contents( $manifest_temp, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX ) || ! @rename( $manifest_temp, $manifest_path ) ) {
			wp_delete_file( $manifest_temp );
			wp_delete_file( $path );
			return new WP_Error( 'nbh_database_backup_manifest_failed', __( 'The database backup manifest could not be created.', 'nbh-performance' ) );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		\NBH\Performance\Modules\Core\Filesystem::chmod( $manifest_path, 0600 );
		if ( ! $this->verify_artifact( $path, $hash, $size ) ) {
			wp_delete_file( $manifest_path );
			wp_delete_file( $path );
			return new WP_Error( 'nbh_database_backup_verify_failed', __( 'The database backup artifact failed final integrity verification.', 'nbh-performance' ) );
		}

		return array(
			'provider'          => 'native_sql',
			'path'              => wp_normalize_path( $path ),
			'manifest_path'     => wp_normalize_path( $manifest_path ),
			'filename'          => $filename,
			'sha256'            => $hash,
			'size_bytes'        => $size,
			'tables'            => $table_count,
			'views'             => $view_count,
			'rows'              => $rows,
			'verified'          => true,
			'restore_available' => true,
			'format_version'    => self::FORMAT_VERSION,
			'consistent'        => empty( $unsafe ) && empty( $unordered ),
		);
	}

	/** Verify a stored artifact against immutable metadata. */
	public function verify_artifact( $path, $expected_hash, $expected_size ) {
		$path = wp_normalize_path( (string) $path );
		if ( ! $this->inside_backup_root( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		$size = (int) filesize( $path );
		if ( $size < 256 || $size !== (int) $expected_size ) {
			return false;
		}
		$hash = hash_file( 'sha256', $path );
		return is_string( $hash ) && hash_equals( strtolower( (string) $expected_hash ), strtolower( $hash ) ) && $this->verify_readable( $path );
	}

	/** @return string|WP_Error */
	private function directory() {
		$root      = DataOwnershipRegistry::private_storage_root();
		$directory = trailingslashit( $root ) . 'database-backups';
		if ( ! wp_mkdir_p( $directory ) || ! \NBH\Performance\Modules\Core\Filesystem::is_writable( $directory ) ) {
			return new WP_Error( 'nbh_database_backup_not_writable', __( 'The private database backup vault is not writable.', 'nbh-performance' ) );
		}
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $files as $name => $contents ) {
			$target = trailingslashit( $directory ) . $name;
			if ( ! is_file( $target ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				@file_put_contents( $target, $contents, LOCK_EX );
			}
		}
		return wp_normalize_path( $directory );
	}

	/** @return array<string,array<int,array<string,string>>>|WP_Error */
	private function database_objects() {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'nbh_database_backup_table_list_failed', __( 'The database table list could not be read.', 'nbh-performance' ) );
		}

		$excluded = preg_split( '/\r\n|\r|\n/', (string) get_option( 'nbh_performance_database_backup_excluded_tables', '' ) );
		$excluded = array_filter( array_map( 'trim', (array) $excluded ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$status_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );
		$engines     = array();
		foreach ( (array) $status_rows as $status ) {
			if ( isset( $status['Name'] ) ) {
				$engines[ $status['Name'] ] = isset( $status['Engine'] ) ? (string) $status['Engine'] : '';
			}
		}

		$result = array(
			'tables' => array(),
			'views'  => array(),
		);
		foreach ( $rows as $row ) {
			$name = isset( $row[0] ) ? (string) $row[0] : '';
			$type = isset( $row[1] ) ? strtoupper( (string) $row[1] ) : 'BASE TABLE';
			if ( '' === $name || in_array( $name, $excluded, true ) ) {
				continue;
			}
			if ( 'VIEW' === $type ) {
				$result['views'][] = array(
					'name'   => $name,
					'engine' => '',
				);
			} else {
				$result['tables'][] = array(
					'name'   => $name,
					'engine' => isset( $engines[ $name ] ) ? $engines[ $name ] : '',
				);
			}
		}
		usort(
			$result['tables'],
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);
		usort(
			$result['views'],
			static function ( $a, $b ) {
				return strcmp( $a['name'], $b['name'] );
			}
		);
		return $result;
	}

	/** @param array<int,array<string,string>> $tables Tables. @return int */
	private function estimated_database_bytes( array $tables ) {
		global $wpdb;
		$total = 0;
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table['name'] ) ), ARRAY_A );
			if ( is_array( $status ) ) {
				$total += absint( isset( $status['Data_length'] ) ? $status['Data_length'] : 0 );
				$total += absint( isset( $status['Index_length'] ) ? $status['Index_length'] : 0 );
			}
		}
		return $total;
	}

	/** @return array<string,string> */
	private function column_types( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		$types = array();
		foreach ( (array) $rows as $row ) {
			if ( isset( $row['Field'] ) ) {
				$types[ $row['Field'] ] = isset( $row['Type'] ) ? strtolower( (string) $row['Type'] ) : '';
			}
		}
		return $types;
	}

	private function order_columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		$groups  = array();
		foreach ( (array) $indexes as $index ) {
			$key_name   = isset( $index['Key_name'] ) ? (string) $index['Key_name'] : '';
			$non_unique = isset( $index['Non_unique'] ) ? absint( $index['Non_unique'] ) : 1;
			$column     = isset( $index['Column_name'] ) ? (string) $index['Column_name'] : '';
			$sequence   = isset( $index['Seq_in_index'] ) ? absint( $index['Seq_in_index'] ) : 0;
			if ( '' === $key_name || '' === $column || ( 'PRIMARY' !== $key_name && 0 !== $non_unique ) ) {
				continue;
			}
			$groups[ $key_name ][ $sequence ] = $column;
		}
		$key = isset( $groups['PRIMARY'] ) ? 'PRIMARY' : ( $groups ? array_key_first( $groups ) : '' );
		if ( '' === $key ) {
			return array();
		}
		ksort( $groups[ $key ] );
		return array_values( $groups[ $key ] );
	}

	private function sql_value( $value, $column_type ) {
		global $wpdb;
		if ( null === $value ) {
			return 'NULL';
		}
		$value = (string) $value;
		if ( preg_match( '/(?:^|\W)(?:tinyblob|mediumblob|longblob|blob|binary|varbinary|bit)(?:\W|$)/i', (string) $column_type ) ) {
			return '' === $value ? "X''" : '0x' . bin2hex( $value );
		}
		// phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_real_escape_string
		$escaped = method_exists( $wpdb, '_real_escape' ) ? $wpdb->_real_escape( $value ) : esc_sql( $value );
		return "'" . $escaped . "'";
	}

	/** @param resource $handle Handle. @param array<int,array<string,string>> $tables Tables. */
	private function dump_triggers( $handle, array $tables ) {
		global $wpdb;
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
			$names = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = %s ORDER BY TRIGGER_NAME',
					$table['name']
				)
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			);
			foreach ( (array) $names as $trigger ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Live maintenance/snapshot verification must reflect current rows and schema, including changes made by other plugins; no cached inventory is safe here.
				$row    = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TRIGGER %i', $trigger ), ARRAY_A );
				$create = '';
				foreach ( (array) $row as $value ) {
					if ( is_string( $value ) && 0 === stripos( ltrim( $value ), 'CREATE' ) ) {
						$create = $value;
						break;
					}
				}
				if ( '' !== $create ) {
					$this->write( $handle, '-- Trigger: ' . $this->identifier( $trigger ) . "\nDROP TRIGGER IF EXISTS " . $this->identifier( $trigger ) . ";\n" . $create . ";\n\n" );
				}
			}
		}
	}

	private function identifier( $identifier ) {
		return '`' . str_replace( '`', '``', (string) $identifier ) . '`';
	}

	/** @param resource $handle Handle. */
	private function write( $handle, $contents ) {
		$length = strlen( (string) $contents );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		if ( fwrite( $handle, (string) $contents ) !== $length ) {
			throw new \RuntimeException( esc_html__( 'The database backup file could not be written completely.', 'nbh-performance' ) );
		}
	}

	private function verify_readable( $path ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Expected filesystem races or unavailable host capabilities are checked by the following failure path; warnings must not corrupt frontend/REST output. A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		$handle = @fopen( $path, 'rb' );
		if ( ! $handle ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		$head = fread( $handle, 128 );
		$size = filesize( $path );
		if ( false === $size || $size < 256 || 0 !== fseek( $handle, max( 0, $size - 512 ) ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
			fclose( $handle );
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		$tail = fread( $handle, 512 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- A streaming/locking resource is required here; WP_Filesystem has no stream-handle API. Ownership/permissions and failure results are checked at this call site.
		fclose( $handle );
		return false !== strpos( (string) $head, 'NBH Performance verified database backup' ) && false !== strpos( (string) $tail, '-- NBH-BACKUP-COMPLETE' );
	}

	private function inside_backup_root( $path ) {
		$root = wp_normalize_path( trailingslashit( DataOwnershipRegistry::private_storage_root() ) . 'database-backups' );
		return 0 === strpos( wp_normalize_path( $path ), trailingslashit( $root ) );
	}
}
