<?php
/**
 * Built-in rule based planner.
 *
 * Admin-defined if/then rules (option pct_planner_rules) are applied server
 * side in three places: candidate scoring/filtering before planning, the
 * "planner_rules" block of the AI prompt, and a post-processing filter on the
 * AI answer. Global weights tune the deterministic fallback planner.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Planner {

	const OPTION = 'pct_planner_rules';

	public static function init() {
		add_action( 'admin_post_pct_save_planner', array( __CLASS__, 'handle_save' ) );
	}

	public static function operators() {
		return array(
			'equals'       => __( 'equals', 'personalized-city-tour' ),
			'not_equals'   => __( 'does not equal', 'personalized-city-tour' ),
			'contains'     => __( 'contains / includes', 'personalized-city-tour' ),
			'not_contains' => __( 'does not include', 'personalized-city-tour' ),
			'gte'          => __( 'is at least', 'personalized-city-tour' ),
			'lte'          => __( 'is at most', 'personalized-city-tour' ),
			'any'          => __( 'always (no condition)', 'personalized-city-tour' ),
		);
	}

	public static function actions() {
		return array(
			'boost'    => __( 'Boost items where…', 'personalized-city-tour' ),
			'penalize' => __( 'Penalize items where…', 'personalized-city-tour' ),
			'exclude'  => __( 'Exclude items where…', 'personalized-city-tour' ),
			'require'  => __( 'Only keep items where…', 'personalized-city-tour' ),
		);
	}

	public static function targets() {
		return array(
			'category'      => __( 'Category slug', 'personalized-city-tour' ),
			'tag'           => __( 'Tag', 'personalized-city-tour' ),
			'type'          => __( 'Type (place / event)', 'personalized-city-tour' ),
			'price_tier'    => __( 'Price tier (free/low/medium/high)', 'personalized-city-tour' ),
			'accessibility' => __( 'Accessibility feature', 'personalized-city-tour' ),
			'indoor'        => __( 'Indoor / outdoor', 'personalized-city-tour' ),
			'family'        => __( 'Family friendly (1/0)', 'personalized-city-tour' ),
			'best_time'     => __( 'Best time of day', 'personalized-city-tour' ),
			'duration_gt'   => __( 'Duration longer than (minutes)', 'personalized-city-tour' ),
			'duration_lt'   => __( 'Duration shorter than (minutes)', 'personalized-city-tour' ),
		);
	}

	public static function defaults() {
		return array(
			'weights' => array(
				'distance_penalty' => 1.5,
				'variety_penalty'  => 1.5,
				'event_bonus'      => 2,
				'max_distance_km'  => 8,
			),
			'constraints' => array(
				'day_start'          => '09:00',
				'day_end'            => '21:00',
				'max_price_tier'     => '',
				'require_accessible' => 0,
			),
			'rules' => array(),
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$conf  = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		$conf['weights']     = wp_parse_args( (array) $conf['weights'], self::defaults()['weights'] );
		$conf['constraints'] = wp_parse_args( (array) $conf['constraints'], self::defaults()['constraints'] );
		$conf['rules']       = array_values( (array) $conf['rules'] );
		usort(
			$conf['rules'],
			function ( $a, $b ) {
				return ( (int) ( $a['priority'] ?? 10 ) ) <=> ( (int) ( $b['priority'] ?? 10 ) );
			}
		);
		return $conf;
	}

	public static function sanitize( array $input ) {
		$d   = self::defaults();
		$out = $d;
		foreach ( array( 'distance_penalty', 'variety_penalty', 'event_bonus', 'max_distance_km' ) as $k ) {
			$out['weights'][ $k ] = max( 0, min( 100, (float) ( $input['weights'][ $k ] ?? $d['weights'][ $k ] ) ) );
		}
		$time = function ( $v, $fallback ) {
			return preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', (string) $v ) ? $v : $fallback;
		};
		$out['constraints']['day_start']          = $time( $input['constraints']['day_start'] ?? '', '09:00' );
		$out['constraints']['day_end']            = $time( $input['constraints']['day_end'] ?? '', '21:00' );
		$out['constraints']['max_price_tier']     = array_key_exists( (string) ( $input['constraints']['max_price_tier'] ?? '' ), PCT_Data::price_tiers() ) ? $input['constraints']['max_price_tier'] : '';
		$out['constraints']['require_accessible'] = empty( $input['constraints']['require_accessible'] ) ? 0 : 1;

		$rules = array();
		foreach ( (array) ( $input['rules'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) || '' === trim( (string) ( $rule['name'] ?? '' ) ) ) {
				continue;
			}
			$when = array();
			foreach ( (array) ( $rule['when'] ?? array() ) as $c ) {
				$op = sanitize_key( $c['op'] ?? '' );
				if ( ! isset( self::operators()[ $op ] ) ) {
					continue;
				}
				$field = sanitize_key( $c['field'] ?? '' );
				if ( 'any' !== $op && '' === $field ) {
					continue;
				}
				$when[] = array(
					'field' => $field,
					'op'    => $op,
					'value' => sanitize_text_field( $c['value'] ?? '' ),
				);
			}
			$then = array();
			foreach ( (array) ( $rule['then'] ?? array() ) as $a ) {
				$action = sanitize_key( $a['action'] ?? '' );
				$target = sanitize_key( $a['target'] ?? '' );
				if ( ! isset( self::actions()[ $action ] ) || ! isset( self::targets()[ $target ] ) ) {
					continue;
				}
				$then[] = array(
					'action' => $action,
					'target' => $target,
					'value'  => sanitize_text_field( $a['value'] ?? '' ),
					'weight' => max( 0, min( 100, (float) ( $a['weight'] ?? 5 ) ) ),
				);
			}
			if ( empty( $then ) ) {
				continue;
			}
			$rules[] = array(
				'id'       => sanitize_key( $rule['id'] ?? '' ) ?: 'r' . wp_generate_password( 6, false, false ),
				'name'     => sanitize_text_field( $rule['name'] ),
				'enabled'  => empty( $rule['enabled'] ) ? 0 : 1,
				'priority' => max( 1, min( 999, absint( $rule['priority'] ?? 10 ) ) ),
				'when'     => $when,
				'then'     => $then,
				'window'   => array(
					'start' => $time( $rule['window']['start'] ?? '', '' ),
					'end'   => $time( $rule['window']['end'] ?? '', '' ),
				),
				'note'     => sanitize_textarea_field( $rule['note'] ?? '' ),
			);
		}
		$out['rules'] = $rules;
		return $out;
	}

	public static function handle_save() {
		check_admin_referer( 'pct_save_planner' );
		if ( ! current_user_can( 'pct_manage' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'personalized-city-tour' ) );
		}
		$input = isset( $_POST['planner'] ) ? wp_unslash( (array) $_POST['planner'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_option( self::OPTION, self::sanitize( $input ), false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'pct-planner', 'pct_msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Evaluation                                                          */
	/* ------------------------------------------------------------------ */

	private static function form_value( array $form, $field ) {
		if ( ! isset( $form[ $field ] ) ) {
			return null;
		}
		return $form[ $field ];
	}

	public static function condition_matches( array $c, array $form ) {
		if ( 'any' === $c['op'] ) {
			return true;
		}
		$val    = self::form_value( $form, $c['field'] );
		$target = (string) $c['value'];
		switch ( $c['op'] ) {
			case 'equals':
				return is_array( $val ) ? in_array( $target, array_map( 'strval', $val ), true ) && 1 === count( $val ) : (string) $val === $target;
			case 'not_equals':
				return is_array( $val ) ? ! in_array( $target, array_map( 'strval', $val ), true ) : (string) $val !== $target;
			case 'contains':
				return is_array( $val ) ? in_array( $target, array_map( 'strval', $val ), true ) : ( null !== $val && '' !== $target && false !== stripos( (string) $val, $target ) );
			case 'not_contains':
				return is_array( $val ) ? ! in_array( $target, array_map( 'strval', $val ), true ) : ( null === $val || false === stripos( (string) $val, $target ) );
			case 'gte':
				return null !== $val && is_numeric( $val ) && (float) $val >= (float) $target;
			case 'lte':
				return null !== $val && is_numeric( $val ) && (float) $val <= (float) $target;
		}
		return false;
	}

	public static function rule_matches( array $rule, array $form ) {
		if ( empty( $rule['enabled'] ) ) {
			return false;
		}
		if ( empty( $rule['when'] ) ) {
			return true;
		}
		foreach ( $rule['when'] as $c ) {
			if ( ! self::condition_matches( $c, $form ) ) {
				return false;
			}
		}
		return true;
	}

	public static function item_matches_target( array $item, $target, $value ) {
		$value = (string) $value;
		switch ( $target ) {
			case 'category':
				return in_array( $value, (array) $item['categories'], true );
			case 'tag':
				foreach ( (array) $item['tags'] as $t ) {
					if ( 0 === strcasecmp( $t, $value ) ) {
						return true;
					}
				}
				return false;
			case 'type':
				return $item['type'] === $value;
			case 'price_tier':
				return $item['price_tier'] === $value;
			case 'accessibility':
				return in_array( $value, (array) $item['accessibility'], true );
			case 'indoor':
				return (string) $item['indoor'] === $value;
			case 'family':
				return (bool) $item['family'] === ( '1' === $value || 'yes' === $value );
			case 'best_time':
				return in_array( $value, (array) $item['best_time'], true );
			case 'duration_gt':
				return (int) $item['duration'] > (int) $value;
			case 'duration_lt':
				return (int) $item['duration'] > 0 && (int) $item['duration'] < (int) $value;
		}
		return false;
	}

	/**
	 * Matching rules for a submitted form (sorted by priority).
	 */
	public static function matching_rules( array $form ) {
		$out = array();
		foreach ( self::get()['rules'] as $rule ) {
			if ( self::rule_matches( $rule, $form ) ) {
				$out[] = $rule;
			}
		}
		return $out;
	}

	/**
	 * Apply global constraints and matching rules to scored candidate items.
	 */
	public static function apply_to_items( array $items, array $form ) {
		$conf = self::get();
		$tier_rank = array( 'free' => 0, 'low' => 1, 'medium' => 2, 'high' => 3 );
		$max_tier  = $conf['constraints']['max_price_tier'];
		if ( $max_tier ) {
			$items = array_values( array_filter( $items, function ( $i ) use ( $tier_rank, $max_tier ) {
				return '' === $i['price_tier'] || ! isset( $tier_rank[ $i['price_tier'] ] ) || $tier_rank[ $i['price_tier'] ] <= $tier_rank[ $max_tier ];
			} ) ) ?: $items;
		}
		if ( ! empty( $conf['constraints']['require_accessible'] ) ) {
			$acc = array_values( array_filter( $items, function ( $i ) {
				return ! empty( $i['accessibility'] );
			} ) );
			$items = $acc ? $acc : $items;
		}

		foreach ( self::matching_rules( $form ) as $rule ) {
			foreach ( $rule['then'] as $a ) {
				switch ( $a['action'] ) {
					case 'boost':
					case 'penalize':
						foreach ( $items as &$item ) {
							if ( self::item_matches_target( $item, $a['target'], $a['value'] ) ) {
								$item['score'] = ( $item['score'] ?? 0 ) + ( 'boost' === $a['action'] ? 1 : -1 ) * (float) $a['weight'];
							}
						}
						unset( $item );
						break;
					case 'exclude':
						$items = array_values( array_filter( $items, function ( $i ) use ( $a ) {
							return ! self::item_matches_target( $i, $a['target'], $a['value'] );
						} ) );
						break;
					case 'require':
						$kept  = array_values( array_filter( $items, function ( $i ) use ( $a ) {
							return self::item_matches_target( $i, $a['target'], $a['value'] );
						} ) );
						$items = $kept ? $kept : $items;
						break;
				}
			}
		}
		usort(
			$items,
			function ( $a, $b ) {
				return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
			}
		);
		return $items;
	}

	/**
	 * Human readable rule statements for the AI prompt.
	 */
	public static function prompt_rules( array $form ) {
		$conf  = self::get();
		$lines = array(
			sprintf( 'Schedule stops between %s and %s.', $conf['constraints']['day_start'], $conf['constraints']['day_end'] ),
		);
		if ( $conf['constraints']['max_price_tier'] ) {
			$lines[] = 'Never suggest items above price tier "' . $conf['constraints']['max_price_tier'] . '".';
		}
		foreach ( self::matching_rules( $form ) as $rule ) {
			$parts = array();
			foreach ( $rule['then'] as $a ) {
				$target = self::targets()[ $a['target'] ] ?? $a['target'];
				switch ( $a['action'] ) {
					case 'boost':
						$parts[] = sprintf( 'prefer items whose %s is "%s" (weight %s)', $target, $a['value'], $a['weight'] );
						break;
					case 'penalize':
						$parts[] = sprintf( 'avoid items whose %s is "%s" (weight %s)', $target, $a['value'], $a['weight'] );
						break;
					case 'exclude':
						$parts[] = sprintf( 'never use items whose %s is "%s"', $target, $a['value'] );
						break;
					case 'require':
						$parts[] = sprintf( 'only use items whose %s is "%s"', $target, $a['value'] );
						break;
				}
			}
			if ( ! empty( $rule['window']['start'] ) && ! empty( $rule['window']['end'] ) ) {
				$parts[] = sprintf( 'keep these stops between %s and %s', $rule['window']['start'], $rule['window']['end'] );
			}
			$line = sprintf( 'Rule "%s" (priority %d): %s.', $rule['name'], $rule['priority'], implode( '; ', $parts ) );
			if ( ! empty( $rule['note'] ) ) {
				$line .= ' ' . $rule['note'];
			}
			$lines[] = $line;
		}
		return $lines;
	}

	/**
	 * Post-process an AI plan: drop stops that violate exclusions and clamp
	 * times into the allowed window.
	 */
	public static function post_filter( array $result, array $items, array $form ) {
		$conf  = self::get();
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}
		$excluded = array();
		foreach ( self::matching_rules( $form ) as $rule ) {
			foreach ( $rule['then'] as $a ) {
				if ( 'exclude' === $a['action'] ) {
					$excluded[] = $a;
				}
			}
		}
		$start = $conf['constraints']['day_start'];
		$end   = $conf['constraints']['day_end'];
		foreach ( (array) ( $result['days'] ?? array() ) as &$day ) {
			if ( ! is_array( $day ) ) {
				continue;
			}
			foreach ( array( 'stops', 'alternatives' ) as $list ) {
				$kept = array();
				foreach ( (array) ( $day[ $list ] ?? array() ) as $stop ) {
					if ( ! is_array( $stop ) ) {
						continue;
					}
					$id   = (int) ( $stop['item_id'] ?? 0 );
					$item = $by_id[ $id ] ?? null;
					if ( ! $item ) {
						continue; // Unknown id: never invented places.
					}
					$drop = false;
					foreach ( $excluded as $a ) {
						if ( self::item_matches_target( $item, $a['target'], $a['value'] ) ) {
							$drop = true;
							break;
						}
					}
					if ( $drop ) {
						continue;
					}
					if ( isset( $stop['time'] ) && preg_match( '/^\d{1,2}:\d{2}$/', (string) $stop['time'] ) ) {
						if ( strcmp( sprintf( '%05s', $stop['time'] ), $start ) < 0 ) {
							$stop['time'] = $start;
						} elseif ( strcmp( sprintf( '%05s', $stop['time'] ), $end ) > 0 ) {
							$stop['time'] = $end;
						}
					}
					$kept[] = $stop;
				}
				$day[ $list ] = $kept;
			}
		}
		unset( $day );
		return $result;
	}
}
