<?php
/**
 * Persistence for generated tours (pct_tour posts).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Tours {

	public static function save( $city_id, array $form, array $result ) {
		$token = wp_generate_password( 24, false, false );
		$name  = isset( $form['name'] ) ? $form['name'] : '';
		$city  = get_the_title( $city_id );

		$post_id = wp_insert_post(
			array(
				'post_type'   => PCT_Post_Types::TOUR,
				'post_status' => 'private',
				/* translators: 1: city, 2: traveller name, 3: date */
				'post_title'  => sprintf( __( '%1$s — %2$s (%3$s)', 'personalized-city-tour' ), $city, $name ? $name : __( 'Traveller', 'personalized-city-tour' ), current_time( 'Y-m-d H:i' ) ),
				'post_author' => get_current_user_id(),
				'meta_input'  => array(
					'_pct_city_id'  => (int) $city_id,
					'_pct_token'    => $token,
					'_pct_form'     => $form,
					'_pct_result'   => $result,
					'_pct_provider' => $result['provider'] ?? '',
					'_pct_email'    => isset( $form['email'] ) ? $form['email'] : '',
				),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return array(
			'id'    => $post_id,
			'token' => $token,
		);
	}

	public static function get( $id, $token = '' ) {
		$post = get_post( $id );
		if ( ! $post || PCT_Post_Types::TOUR !== $post->post_type ) {
			return null;
		}
		$stored = get_post_meta( $post->ID, '_pct_token', true );
		$owner  = get_current_user_id() && (int) $post->post_author === get_current_user_id();
		if ( ! current_user_can( 'pct_manage' ) && ! $owner && ( ! $token || ! hash_equals( (string) $stored, (string) $token ) ) ) {
			return null;
		}
		return array(
			'id'      => $post->ID,
			'token'   => $stored,
			'city_id' => (int) get_post_meta( $post->ID, '_pct_city_id', true ),
			'form'    => (array) get_post_meta( $post->ID, '_pct_form', true ),
			'result'  => (array) get_post_meta( $post->ID, '_pct_result', true ),
			'created' => $post->post_date,
		);
	}
}
