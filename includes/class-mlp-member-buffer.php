<?php
defined( 'ABSPATH' ) || exit;

/**
 * Collects members from a source and writes them to the members table in batches.
 */
class MLP_Member_Buffer {

	const BATCH_SIZE = 200;

	/** @var int */
	private $list_id;

	/** @var string */
	private $run;

	/** @var string */
	private $now;

	/** @var array<string, array{0: string, 1: string, 2: string}> Keyed by email so a batch never repeats an address. */
	private $rows = array();

	/**
	 * @param int    $list_id List ID.
	 * @param string $run     Identifier of this refresh run.
	 * @param string $now     UTC datetime stored as the time members were added.
	 */
	public function __construct( $list_id, $run, $now ) {
		$this->list_id = $list_id;
		$this->run     = $run;
		$this->now     = $now;
	}

	/**
	 * @param string $email Address from the source (normalised here; invalid ones are dropped).
	 * @param string $name  Display name.
	 * @param string $ref   Where the address came from, e.g. "entry:42".
	 */
	public function add( $email, $name, $ref ) {
		$email = mlp_normalize_email( $email );
		if ( ! $email ) {
			return;
		}

		$name = function_exists( 'mb_substr' ) ? mb_substr( (string) $name, 0, 190 ) : substr( (string) $name, 0, 190 );

		$this->rows[ $email ] = array( $email, $name, substr( (string) $ref, 0, 190 ) );

		if ( count( $this->rows ) >= self::BATCH_SIZE ) {
			$this->flush();
		}
	}

	/**
	 * Write the buffered rows, updating those already on the list.
	 */
	public function flush() {
		global $wpdb;

		if ( ! $this->rows ) {
			return;
		}

		$table        = MLP_DB::table( 'members' );
		$placeholders = array();
		$values       = array();
		foreach ( $this->rows as $member ) {
			$placeholders[] = '(%d,%s,%s,%s,%s,%s)';
			array_push( $values, $this->list_id, $member[0], $member[1], $member[2], $this->run, $this->now );
		}

		// The placeholder list is built from the batch size, which the sniffs cannot see.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (list_id,email,name,source_ref,seen_run,added_at) VALUES " . implode( ',', $placeholders ) .
				' ON DUPLICATE KEY UPDATE name = VALUES(name), source_ref = VALUES(source_ref), seen_run = VALUES(seen_run)',
				$values
			)
		);
		// phpcs:enable

		$this->rows = array();
	}
}
