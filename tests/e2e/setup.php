<?php
/**
 * Seeds a throwaway site for bin/e2e-http.sh (run with `wp eval-file`).
 * Prints one JSON object with the URLs the HTTP checks exercise.
 */

global $wpdb;

$mlp_entries = $wpdb->prefix . 'wpforms_entries';
$wpdb->query( "DROP TABLE IF EXISTS {$mlp_entries}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	"CREATE TABLE {$mlp_entries} (
		entry_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		form_id bigint(20) unsigned NOT NULL,
		status varchar(30) NOT NULL DEFAULT '',
		fields longtext NOT NULL,
		date datetime NOT NULL,
		PRIMARY KEY  (entry_id)
	) {$wpdb->get_charset_collate()}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);

$form_id = wp_insert_post(
	array(
		'post_type'    => 'wpforms',
		'post_status'  => 'publish',
		'post_title'   => 'E2E form',
		'post_content' => wp_slash(
			wp_json_encode(
				array(
					'fields' => array(
						1 => array(
							'id'    => 1,
							'type'  => 'name',
							'label' => 'Name',
						),
						2 => array(
							'id'    => 2,
							'type'  => 'email',
							'label' => 'Email',
						),
					),
				)
			)
		),
	)
);

foreach ( array(
	array( 'Ann', 'ann@example.com' ),
	array( 'Ann again', 'ANN@example.com' ),
	array( 'Ben', 'ben@example.com' ),
) as $person ) {
	$wpdb->insert(
		$mlp_entries,
		array(
			'form_id' => $form_id,
			'status'  => '',
			'date'    => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			'fields'  => wp_json_encode(
				array(
					1 => array(
						'name'  => 'Name',
						'value' => $person[0],
						'id'    => 1,
						'type'  => 'name',
					),
					2 => array(
						'name'  => 'Email',
						'value' => $person[1],
						'id'    => 2,
						'type'  => 'email',
					),
				)
			),
		)
	);
}

$marketing = MLP_Lists::save(
	0,
	'E2E marketing',
	'marketing',
	array(
		'form_id'    => $form_id,
		'name_field' => '1',
	)
);
$product   = MLP_Lists::save( 0, 'E2E product', 'product', array( 'wc_mode' => 'all_customers' ) );
MLP_Refresher::refresh_list( MLP_Lists::get( $marketing ) );

echo wp_json_encode(
	array(
		'marketing_list' => $marketing,
		'product_list'   => $product,
		'ann_url'        => MLP_Unsubscribe::url( $marketing, 'ann@example.com' ),
		'ben_url'        => MLP_Unsubscribe::url( $marketing, 'ben@example.com' ),
		'product_url'    => MLP_Unsubscribe::url( $product, 'alice@example.com' ),
	)
);
