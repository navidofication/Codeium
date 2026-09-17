<?php
/**
 * Builds personalized tour options ("Personal Tour Messages") from the city
 * database and a submitted travel preference form. Uses the configured AI
 * provider when available and a rule based planner otherwise (or on failure).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Tour_Generator {

	/**
	 * @param int   $city_id City post ID.
	 * @param array $form    Validated form data.
	 * @return array|WP_Error
	 */
	public static function generate( $city_id, array $form ) {
		$city = PCT_Data::city( $city_id );
		if ( ! $city ) {
			return new WP_Error( 'pct_no_city', __( 'Unknown city.', 'personalized-city-tour' ) );
		}

		$items = self::candidate_items( $city, $form );
		if ( empty( $items ) ) {
			return new WP_Error( 'pct_no_data', __( 'There is no city data available for this destination yet.', 'personalized-city-tour' ) );
		}

		$days     = self::days_count( $form );
		$per_day  = self::stops_per_day( $form );
		$result   = null;
		$provider = 'rules';
		$warning  = '';

		if ( PCT_AI::enabled() ) {
			$ai = self::ai_plan( $city, $form, $items, $days, $per_day );
			if ( is_wp_error( $ai ) ) {
				$warning = $ai->get_error_message();
			} else {
				$result   = $ai;
				$provider = PCT_Settings::get( 'ai_provider' );
			}
		}
		if ( null === $result ) {
			$result = self::rule_based_plan( $city, $form, $items, $days, $per_day );
		}

		$result = self::finalize( $result, $city, $form, $items );
		$result['provider']     = $provider;
		$result['generated_at'] = current_time( 'mysql' );
		if ( $warning && current_user_can( 'pct_manage' ) ) {
			$result['warning'] = $warning;
		}

		return apply_filters( 'pct_generated_tour', $result, $city, $form );
	}

	/* ---------------------------------------------------------------------
	 * Profile helpers
	 * ------------------------------------------------------------------ */

	public static function days_count( array $form ) {
		$nights = isset( $form['nights'] ) ? (int) $form['nights'] : 1;
		if ( ! empty( $form['date_start'] ) && ! empty( $form['date_end'] ) ) {
			$diff = ( strtotime( $form['date_end'] ) - strtotime( $form['date_start'] ) ) / DAY_IN_SECONDS;
			if ( $diff >= 0 ) {
				$nights = max( $nights, (int) $diff );
			}
		}
		return max( 1, min( 7, $nights ) );
	}

	public static function stops_per_day( array $form ) {
		$base = (int) PCT_Settings::get( 'stops_per_day', 3 );
		switch ( $form['pace'] ?? 'moderate' ) {
			case 'slow':
				return max( 1, $base - 1 );
			case 'fast':
				return min( 8, $base + 1 );
			default:
				return $base;
		}
	}

	public static function needs_accessible( array $form ) {
		$lim = (array) ( $form['mobility_limitations'] ?? array() );
		return in_array( 'wheelchair', $lim, true );
	}

	private static function interest_weights( array $form ) {
		$interests = array_values( (array) ( $form['interests'] ?? array() ) );
		$n         = count( $interests );
		$weights   = array();
		foreach ( $interests as $i => $slug ) {
			$weights[ $slug ] = 10 * ( $n - $i ) / max( 1, $n ); // Rank 1 => 10.
		}
		return $weights;
	}

	/**
	 * Travel window [from, to] as Y-m-d, or empty strings when flexible.
	 */
	private static function date_window( array $form ) {
		$mode = $form['dates_mode'] ?? 'flexible';
		if ( 'custom' === $mode && ! empty( $form['date_start'] ) ) {
			$from = $form['date_start'];
			$to   = ! empty( $form['date_end'] ) ? $form['date_end'] : gmdate( 'Y-m-d', strtotime( $from ) + self::days_count( $form ) * DAY_IN_SECONDS );
			return array( $from, $to );
		}
		if ( 'this_week' === $mode ) {
			return array( current_time( 'Y-m-d' ), gmdate( 'Y-m-d', current_time( 'timestamp' ) + 7 * DAY_IN_SECONDS ) );
		}
		return array( current_time( 'Y-m-d' ), gmdate( 'Y-m-d', current_time( 'timestamp' ) + 60 * DAY_IN_SECONDS ) );
	}

	/* ---------------------------------------------------------------------
	 * Candidate selection & scoring
	 * ------------------------------------------------------------------ */

	private static function candidate_items( array $city, array $form ) {
		list( $from, $to ) = self::date_window( $form );
		$items = array_merge( PCT_Data::places( $city['id'] ), PCT_Data::events( $city['id'], $from, $to ) );

		$accessible = self::needs_accessible( $form );
		$budget     = $form['budget_tier'] ?? 'medium';

		$filtered = array();
		foreach ( $items as $item ) {
			if ( $accessible && ! in_array( 'wheelchair', $item['accessibility'], true ) ) {
				continue;
			}
			if ( 'low' === $budget && 'high' === $item['price_tier'] ) {
				continue;
			}
			$item['score'] = self::score( $item, $form );
			$filtered[]    = $item;
		}

		// Hard filters may leave nothing; relax accessibility filter but flag it.
		if ( empty( $filtered ) && $accessible ) {
			foreach ( $items as $item ) {
				$item['score']      = self::score( $item, $form );
				$item['acc_warning'] = true;
				$filtered[]         = $item;
			}
		}

		usort(
			$filtered,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$max = (int) PCT_Settings::get( 'ai_max_items', 60 );
		return array_slice( $filtered, 0, $max );
	}

	private static function score( array $item, array $form ) {
		$weights = self::interest_weights( $form );
		$score   = 0.0;
		foreach ( $item['categories'] as $cat ) {
			if ( isset( $weights[ $cat ] ) ) {
				$score += $weights[ $cat ];
			}
		}
		if ( empty( $weights ) ) {
			$score += 3;
		}

		$local = (int) ( $form['local_experience'] ?? 5 );
		if ( in_array( 'social', $item['categories'], true ) || in_array( 'markets', $item['categories'], true ) ) {
			$score += $local / 4;
		}
		if ( 'event' === $item['type'] ) {
			$score += 2 + $local / 5; // Live events are inherently local.
		}

		$styles = (array) ( $form['travel_style'] ?? array() );
		if ( in_array( 'photography', $styles, true ) && in_array( 'viewpoints', $item['categories'], true ) ) {
			$score += 3;
		}
		if ( in_array( 'budget', $styles, true ) && in_array( $item['price_tier'], array( 'free', 'low' ), true ) ) {
			$score += 2;
		}
		if ( in_array( 'luxury', $styles, true ) && 'high' === $item['price_tier'] ) {
			$score += 2;
		}

		if ( 'family' === ( $form['group_type'] ?? '' ) ) {
			if ( $item['family'] || in_array( 'family', $item['categories'], true ) ) {
				$score += 4;
			}
			if ( in_array( 'nightlife', $item['categories'], true ) ) {
				$score -= 6;
			}
		}

		$times = (array) ( $form['preferred_times'] ?? array() );
		if ( $times && $item['best_time'] && array_intersect( $times, $item['best_time'] ) ) {
			$score += 1.5;
		}

		$lim = (array) ( $form['mobility_limitations'] ?? array() );
		if ( array_intersect( $lim, array( 'stroller', 'no_stairs', 'limited_walking' ) ) ) {
			if ( array_intersect( $item['accessibility'], array( 'wheelchair', 'no_stairs', 'stroller' ) ) ) {
				$score += 2;
			} else {
				$score -= 1;
			}
		}

		$budget = $form['budget_tier'] ?? 'medium';
		if ( 'low' === $budget && in_array( $item['price_tier'], array( 'free', 'low' ), true ) ) {
			$score += 1;
		}
		if ( 'high' === $budget && 'high' === $item['price_tier'] ) {
			$score += 1;
		}

		return $score;
	}

	/* ---------------------------------------------------------------------
	 * Rule based planner
	 * ------------------------------------------------------------------ */

	private static function rule_based_plan( array $city, array $form, array $items, $days, $per_day ) {
		$pool      = $items;
		$plan_days = array();
		$times     = self::slot_times( $form, $per_day );
		$weights   = self::interest_weights( $form );

		for ( $d = 1; $d <= $days; $d++ ) {
			if ( empty( $pool ) ) {
				break;
			}
			$selected = array();
			// Anchor: best remaining item.
			$anchor     = array_shift( $pool );
			$selected[] = $anchor;

			while ( count( $selected ) < $per_day && ! empty( $pool ) ) {
				$last  = end( $selected );
				$best  = null;
				$bestv = -INF;
				foreach ( $pool as $idx => $cand ) {
					$dist  = PCT_Data::distance( $last['lat'], $last['lng'], $cand['lat'], $cand['lng'] );
					$value = $cand['score'] - min( 8, $dist * 1.5 );
					// Encourage variety within a day.
					if ( array_intersect( $cand['categories'], $last['categories'] ) ) {
						$value -= 1.5;
					}
					if ( $value > $bestv ) {
						$bestv = $value;
						$best  = $idx;
					}
				}
				$selected[] = $pool[ $best ];
				unset( $pool[ $best ] );
				$pool = array_values( $pool );
			}

			$selected = self::order_route( $selected );
			$stops    = array();
			foreach ( $selected as $i => $item ) {
				$stops[] = array(
					'item_id' => $item['id'],
					'time'    => $times[ $i ] ?? '',
					'note'    => self::stop_note( $item, $form ),
				);
			}

			// Alternatives: two more nearby suggestions.
			$alternatives = array();
			foreach ( array_slice( $pool, 0, 2 ) as $alt ) {
				$alternatives[] = array(
					'item_id' => $alt['id'],
					'note'    => __( 'Swap in if you have extra time.', 'personalized-city-tour' ),
				);
			}

			$main_cats = array();
			foreach ( $selected as $item ) {
				foreach ( $item['categories'] as $c ) {
					$main_cats[ $c ] = ( $main_cats[ $c ] ?? 0 ) + ( $weights[ $c ] ?? 1 );
				}
			}
			arsort( $main_cats );
			$theme = self::category_label( key( $main_cats ) );

			$plan_days[] = array(
				'day'          => $d,
				/* translators: 1: day number, 2: theme */
				'title'        => sprintf( __( 'Day %1$d — %2$s', 'personalized-city-tour' ), $d, $theme ),
				'summary'      => self::day_summary( $selected, $form, $city ),
				'stops'        => $stops,
				'alternatives' => $alternatives,
			);
		}

		return array( 'days' => $plan_days );
	}

	/**
	 * Nearest-neighbour ordering starting from the highest scored stop.
	 */
	private static function order_route( array $stops ) {
		if ( count( $stops ) < 3 ) {
			return $stops;
		}
		$ordered = array( array_shift( $stops ) );
		while ( $stops ) {
			$last = end( $ordered );
			$bi   = 0;
			$bd   = INF;
			foreach ( $stops as $i => $s ) {
				$d = PCT_Data::distance( $last['lat'], $last['lng'], $s['lat'], $s['lng'] );
				if ( $d < $bd ) {
					$bd = $d;
					$bi = $i;
				}
			}
			$ordered[] = $stops[ $bi ];
			unset( $stops[ $bi ] );
		}
		return $ordered;
	}

	private static function slot_times( array $form, $per_day ) {
		$pref = (array) ( $form['preferred_times'] ?? array() );
		$start = 9;
		if ( $pref && ! in_array( 'morning', $pref, true ) ) {
			$start = in_array( 'afternoon', $pref, true ) ? 13 : 17;
		}
		$gap   = 'slow' === ( $form['pace'] ?? '' ) ? 3.5 : 2.5;
		$times = array();
		for ( $i = 0; $i < $per_day; $i++ ) {
			$h       = $start + $i * $gap;
			$hour    = (int) floor( $h ) % 24;
			$minute  = ( $h - floor( $h ) ) >= 0.5 ? '30' : '00';
			$times[] = sprintf( '%02d:%s', $hour, $minute );
		}
		return $times;
	}

	private static function stop_note( array $item, array $form ) {
		$parts = array();
		if ( 'event' === $item['type'] && $item['start'] ) {
			/* translators: %s: event start date/time */
			$parts[] = sprintf( __( 'Event starts %s.', 'personalized-city-tour' ), $item['start'] );
		}
		if ( $item['duration'] ) {
			/* translators: %d: minutes */
			$parts[] = sprintf( __( 'Plan about %d minutes.', 'personalized-city-tour' ), $item['duration'] );
		}
		if ( $item['hours'] ) {
			/* translators: %s: opening hours */
			$parts[] = sprintf( __( 'Hours: %s.', 'personalized-city-tour' ), $item['hours'] );
		}
		if ( 'free' === $item['price_tier'] ) {
			$parts[] = __( 'Free entry.', 'personalized-city-tour' );
		}
		if ( in_array( 'food', $item['categories'], true ) && ! empty( $form['dietary'] ) && ! in_array( 'none', (array) $form['dietary'], true ) ) {
			$parts[] = __( 'Mention your dietary needs when ordering.', 'personalized-city-tour' );
		}
		return implode( ' ', $parts );
	}

	private static function day_summary( array $selected, array $form, array $city ) {
		$names = wp_list_pluck( $selected, 'name' );
		$pace  = $form['pace'] ?? 'moderate';
		$intro = 'slow' === $pace
			? __( 'A relaxed day with time to linger:', 'personalized-city-tour' )
			: ( 'fast' === $pace ? __( 'A full day covering:', 'personalized-city-tour' ) : __( 'A balanced day visiting:', 'personalized-city-tour' ) );
		$summary = $intro . ' ' . implode( ', ', $names ) . '.';

		$modes = (array) ( $form['mobility_modes'] ?? array() );
		if ( in_array( 'walking', $modes, true ) ) {
			$summary .= ' ' . __( 'Stops are ordered to keep walking distances short.', 'personalized-city-tour' );
		} elseif ( in_array( 'public_transport', $modes, true ) ) {
			$summary .= ' ' . __( 'Use public transport between stops.', 'personalized-city-tour' );
		}
		return $summary;
	}

	private static function category_label( $slug ) {
		$labels = PCT_Post_Types::default_categories();
		if ( isset( $labels[ $slug ] ) ) {
			return $labels[ $slug ];
		}
		$term = $slug ? get_term_by( 'slug', $slug, PCT_Post_Types::CATEGORY ) : null;
		return $term ? $term->name : __( 'Highlights', 'personalized-city-tour' );
	}

	/* ---------------------------------------------------------------------
	 * AI planner
	 * ------------------------------------------------------------------ */

	private static function ai_plan( array $city, array $form, array $items, $days, $per_day ) {
		$schema  = PCT_Form_Builder::get_schema();
		$fields  = PCT_Form_Builder::field_map( $schema );
		$profile = array();
		foreach ( $form as $key => $value ) {
			if ( in_array( $key, array( 'name', 'email', 'phone' ), true ) ) {
				continue; // Never send personal contact data to the provider.
			}
			$label           = isset( $fields[ $key ] ) ? $fields[ $key ]['label'] : $key;
			$profile[ $key ] = array(
				'question' => $label,
				'answer'   => isset( $fields[ $key ] ) ? PCT_Form_Builder::display_value( $fields[ $key ], $value ) : $value,
			);
		}

		$compact = array();
		foreach ( $items as $item ) {
			$row = array(
				'id'         => $item['id'],
				'type'       => $item['type'],
				'name'       => $item['name'],
				'categories' => $item['categories'],
				'tags'       => $item['tags'],
				'summary'    => $item['summary'],
				'price'      => $item['price_tier'],
				'access'     => $item['accessibility'],
				'duration'   => $item['duration'],
				'best_time'  => $item['best_time'],
				'hours'      => $item['hours'],
			);
			if ( 'event' === $item['type'] ) {
				$row['starts']    = $item['start'];
				$row['ends']      = $item['end'];
				$row['organizer'] = $item['organizer'];
			}
			$compact[] = $row;
		}

		$system = 'You are an experienced local tour planner for ' . $city['name'] . ( $city['country'] ? ', ' . $city['country'] : '' ) . '. '
			. 'You plan personalized city tours strictly from the provided city database items. '
			. 'Never invent places: every stop must reference an item id from the list. '
			. 'Respect accessibility, budget, dietary, pace and time-of-day preferences. '
			. 'If the traveller uses a wheelchair, only use items whose access list contains "wheelchair". '
			. 'Return ONLY valid JSON matching this schema: '
			. '{"days":[{"day":1,"title":"string","summary":"string (2-4 sentences)","stops":[{"item_id":123,"time":"HH:MM","note":"string, practical tip"}],'
			. '"alternatives":[{"item_id":123,"note":"string"}],"accessibility_notes":"string","quick_search_hints":["string"]}]}';

		$user = wp_json_encode(
			array(
				'instructions' => array(
					'days'          => $days,
					'stops_per_day' => $per_day,
					'alternatives_per_day' => 2,
					'quick_search_hints'   => 'Short search phrases the traveller can type into a maps/search app near each stop (3-5 per day).',
					'language'      => get_bloginfo( 'language' ),
					'today'         => current_time( 'Y-m-d' ),
				),
				'city'         => array(
					'name'        => $city['name'],
					'country'     => $city['country'],
					'description' => wp_trim_words( $city['description'], 80 ),
				),
				'traveller'    => $profile,
				'items'        => $compact,
			),
			JSON_UNESCAPED_UNICODE
		);

		$json = PCT_AI::complete_json( $system, $user );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( empty( $json['days'] ) || ! is_array( $json['days'] ) ) {
			return new WP_Error( 'pct_ai_shape', __( 'AI response did not contain any days.', 'personalized-city-tour' ) );
		}
		return $json;
	}

	/* ---------------------------------------------------------------------
	 * Normalisation shared by both planners
	 * ------------------------------------------------------------------ */

	private static function finalize( array $result, array $city, array $form, array $items ) {
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}
		$mode       = PCT_Maps::travel_mode( $form );
		$accessible = self::needs_accessible( $form );
		$days       = array();
		$n          = 0;

		foreach ( (array) $result['days'] as $day ) {
			if ( ! is_array( $day ) ) {
				continue;
			}
			$n++;
			$stops = array();
			foreach ( (array) ( $day['stops'] ?? array() ) as $stop ) {
				$s = self::normalize_stop( $stop, $by_id, $accessible );
				if ( $s ) {
					$stops[] = $s;
				}
			}
			if ( empty( $stops ) ) {
				$n--;
				continue;
			}
			$alts = array();
			foreach ( (array) ( $day['alternatives'] ?? array() ) as $alt ) {
				$a = self::normalize_stop( $alt, $by_id, $accessible );
				if ( $a ) {
					$alts[] = $a;
				}
			}

			$acc_notes = sanitize_textarea_field( (string) ( $day['accessibility_notes'] ?? '' ) );
			if ( '' === $acc_notes ) {
				$acc_notes = self::accessibility_notes( $stops, $form );
			}
			$hints = array();
			foreach ( (array) ( $day['quick_search_hints'] ?? array() ) as $h ) {
				if ( is_string( $h ) && '' !== trim( $h ) ) {
					$hints[] = sanitize_text_field( $h );
				}
			}
			if ( empty( $hints ) ) {
				$hints = self::search_hints( $stops, $city, $form );
			}

			$days[] = array(
				'day'                 => $n,
				/* translators: %d: day number */
				'title'               => sanitize_text_field( (string) ( $day['title'] ?? sprintf( __( 'Day %d', 'personalized-city-tour' ), $n ) ) ),
				'summary'             => sanitize_textarea_field( (string) ( $day['summary'] ?? '' ) ),
				'stops'               => $stops,
				'alternatives'        => $alts,
				'accessibility_notes' => $acc_notes,
				'quick_search_hints'  => array_slice( $hints, 0, 6 ),
				'map'                 => array(
					'mode'       => $mode,
					'google_url' => PCT_Maps::google_directions_url( $stops, $mode ),
					'osm_url'    => PCT_Maps::osm_directions_url( $stops, $mode ),
				),
			);
		}

		return array(
			'city' => array(
				'id'     => $city['id'],
				'name'   => $city['name'],
				'lat'    => $city['lat'],
				'lng'    => $city['lng'],
				'zoom'   => $city['zoom'],
				'mymaps' => $city['mymaps'],
			),
			'days' => $days,
		);
	}

	private static function normalize_stop( $stop, array $by_id, $accessible ) {
		if ( ! is_array( $stop ) ) {
			return null;
		}
		$id   = (int) ( $stop['item_id'] ?? $stop['id'] ?? 0 );
		$item = isset( $by_id[ $id ] ) ? $by_id[ $id ] : PCT_Data::item( $id );
		if ( ! $item ) {
			return null;
		}
		$tags = array_values( array_unique( array_merge( array_map( array( __CLASS__, 'category_label' ), $item['categories'] ), $item['tags'] ) ) );
		if ( in_array( 'wheelchair', $item['accessibility'], true ) ) {
			$tags[] = __( 'Accessible', 'personalized-city-tour' );
		}
		if ( 'event' === $item['type'] ) {
			$tags[] = __( 'Event', 'personalized-city-tour' );
		}
		$time = (string) ( $stop['time'] ?? '' );
		if ( ! preg_match( '/^\d{1,2}:\d{2}$/', $time ) ) {
			$time = '';
		}
		return array(
			'item_id'       => $item['id'],
			'type'          => $item['type'],
			'name'          => $item['name'],
			'summary'       => $item['summary'],
			'address'       => $item['address'],
			'lat'           => $item['lat'],
			'lng'           => $item['lng'],
			'link'          => $item['link'],
			'tags'          => $tags,
			'time'          => $time,
			'duration'      => $item['duration'],
			'note'          => sanitize_textarea_field( (string) ( $stop['note'] ?? '' ) ),
			'accessible'    => in_array( 'wheelchair', $item['accessibility'], true ),
			'accessibility' => $item['accessibility'],
			'price_tier'    => $item['price_tier'],
			'start'         => $item['start'] ?? '',
			'organizer'     => $item['organizer'] ?? '',
			'organizer_url' => $item['organizer_url'] ?? '',
			'acc_warning'   => $accessible && ! in_array( 'wheelchair', $item['accessibility'], true ),
		);
	}

	private static function accessibility_notes( array $stops, array $form ) {
		$lim = (array) ( $form['mobility_limitations'] ?? array() );
		$lim = array_diff( $lim, array( 'none' ) );
		if ( empty( $lim ) ) {
			$acc = array_filter( $stops, function ( $s ) { return $s['accessible']; } );
			return count( $acc ) === count( $stops )
				? __( 'All stops on this day are wheelchair accessible.', 'personalized-city-tour' )
				: '';
		}
		$notes = array();
		foreach ( $stops as $s ) {
			$labels = array();
			foreach ( $s['accessibility'] as $a ) {
				$labels[] = PCT_Data::accessibility_options()[ $a ] ?? $a;
			}
			$notes[] = $s['name'] . ': ' . ( $labels ? implode( ', ', $labels ) : __( 'no accessibility information — check before visiting', 'personalized-city-tour' ) );
		}
		return implode( "\n", $notes );
	}

	private static function search_hints( array $stops, array $city, array $form ) {
		$hints = array();
		foreach ( $stops as $s ) {
			$hints[] = $s['name'] . ' ' . __( 'opening hours', 'personalized-city-tour' );
		}
		$interests = (array) ( $form['interests'] ?? array() );
		if ( in_array( 'food', $interests, true ) && $stops ) {
			$hints[] = __( 'restaurants near', 'personalized-city-tour' ) . ' ' . $stops[0]['name'];
		}
		if ( in_array( 'public_transport', (array) ( $form['mobility_modes'] ?? array() ), true ) ) {
			$hints[] = $city['name'] . ' ' . __( 'public transport day ticket', 'personalized-city-tour' );
		}
		return $hints;
	}
}
