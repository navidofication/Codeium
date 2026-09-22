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
		$name  = isset( $form['name'] ) ? trim( (string) $form['name'] ) : '';
		$city  = get_the_title( $city_id );
		$lang  = isset( $result['lang'] ) ? $result['lang'] : PCT_I18n::current();
		$date  = current_time( 'Y-m-d' );
		$time  = current_time( 'H:i' );

		// "Full name – Language – City – Date Time" (name only when allowed to be stored).
		$parts = array();
		if ( '' !== $name ) {
			$parts[] = $name;
		}
		$parts[] = strtoupper( $lang );
		$parts[] = $city;
		$parts[] = PCT_I18n::format_date( current_time( 'timestamp' ), $lang, true );
		$title   = implode( ' – ', $parts );

		$slug_parts = array_filter( array( $name, $city, $date, str_replace( ':', '', $time ) ) );
		$slug       = sanitize_title( implode( '-', $slug_parts ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( 'tour-' . $city_id . '-' . $date . '-' . $time );
		}
		$slug = wp_unique_post_slug( $slug, 0, 'private', PCT_Post_Types::TOUR, 0 );

		$post_id = wp_insert_post(
			array(
				'post_type'   => PCT_Post_Types::TOUR,
				'post_status' => 'private',
				'post_title'  => $title,
				'post_name'   => $slug,
				'post_author' => get_current_user_id(),
				'meta_input'  => array(
					'_pct_city_id'  => (int) $city_id,
					'_pct_token'    => $token,
					'_pct_form'     => $form,
					'_pct_result'   => $result,
					'_pct_provider' => $result['provider'] ?? '',
					'_pct_email'    => isset( $form['email'] ) ? $form['email'] : '',
					'_pct_lang'     => $lang,
					'_pct_slug'     => $slug,
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
			'url'   => self::url( $post_id, $token, $slug ),
		);
	}

	/**
	 * Shareable link that re-opens the tour on the page it was created on.
	 */
	public static function url( $post_id, $token, $slug = '' ) {
		$base = apply_filters( 'pct_tour_base_url', home_url( '/' ) );
		if ( ! $slug ) {
			$slug = get_post_meta( $post_id, '_pct_slug', true );
		}
		return add_query_arg(
			array(
				'pct_tour'  => (int) $post_id,
				'pct_token' => $token,
				'pct_slug'  => $slug,
			),
			$base
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
			'url'     => self::url( $post->ID, $stored ),
			'city_id' => (int) get_post_meta( $post->ID, '_pct_city_id', true ),
			'form'    => (array) get_post_meta( $post->ID, '_pct_form', true ),
			'result'  => (array) get_post_meta( $post->ID, '_pct_result', true ),
			'created' => $post->post_date,
		);
	}
}
