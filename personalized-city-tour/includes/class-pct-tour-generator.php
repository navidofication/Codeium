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
	 * @param array $opts    lang (ui language code).
	 * @return array|WP_Error
	 */
	public static function generate( $city_id, array $form, array $opts = array() ) {
		$city = PCT_Data::city( $city_id );
		if ( ! $city ) {
			return new WP_Error( 'pct_no_city', __( 'Unknown city.', 'personalized-city-tour' ) );
		}
		$lang = isset( $opts['lang'] ) ? PCT_I18n::normalize( $opts['lang'] ) : PCT_I18n::current();

		$items = self::candidate_items( $city, $form );
		$channel_items = PCT_Channel_Intel::candidates( $city, $form, array( 'cached_only' => false ) );
		$channel_local_count = count( $channel_items );
		if ( $channel_items ) {
			foreach ( $channel_items as $channel_item ) {
				$channel_item['score'] = self::score( $channel_item, $form ) + 4;
				$items[] = $channel_item;
			}
		}
		if ( empty( $items ) ) {
			return new WP_Error( 'pct_no_data', __( 'There is no city data available for this destination yet.', 'personalized-city-tour' ) );
		}
		$items = PCT_Planner::apply_to_items( $items, $form );

		// Build the My Maps context from the complete city/date candidate pool, not
		// only the top AI-scored 40 items. Otherwise a curated map point can be
		// silently discarded before it ever reaches the matcher.
		$all_map_items = array_merge(
			PCT_Data::places( $city['id'] ),
			PCT_Data::events( $city['id'], self::date_window( $form )[0], self::date_window( $form )[1] )
		);
		$all_map_items = PCT_Planner::apply_to_items( $all_map_items, $form );
		$mymap_context = PCT_Data::curated_mymap_context( $city['id'], $all_map_items );
		$mymap_ids = array_flip( array_map( 'intval', (array) ( $mymap_context['matched_item_ids'] ?? array() ) ) );

		/*
		 * Carry route metadata onto the exact items sent to the planner. This
		 * prevents a curated My Maps route from becoming passive context only.
		 */
		$route_meta = (array) ( $mymap_context['route_item_meta'] ?? array() );
		$mymap_item_meta = (array) ( $mymap_context['mymap_item_meta'] ?? array() );
		foreach ( $items as &$candidate_item ) {
			$rid = (int) $candidate_item['id'];
			if ( isset( $mymap_item_meta[ $rid ] ) ) {
				$candidate_item['mymap_matched'] = true;
				$candidate_item['mymap_url'] = (string) ( $mymap_item_meta[ $rid ]['url'] ?? '' );
				$candidate_item['mymap_label'] = (string) ( $mymap_item_meta[ $rid ]['label'] ?? '' );
			}
			if ( isset( $route_meta[ $rid ] ) ) {
				$candidate_item['mymap_matched'] = true;
				$candidate_item['mymap_route_order'] = (int) $route_meta[ $rid ]['order'];
				$candidate_item['mymap_route_map'] = (string) $route_meta[ $rid ]['map'];
				$candidate_item['mymap_route_name'] = (string) $route_meta[ $rid ]['name'];
				$candidate_item['mymap_route_folder'] = (string) $route_meta[ $rid ]['folder'];
			}
			if ( ! empty( $candidate_item['mymap_url'] ) ) {
				$candidate_item['mymap_matched'] = true;
			}
		}
		unset( $candidate_item );

		// Public My Maps can contain curated destinations that have not yet been
		// entered into City Data. Treat those map points as first-class virtual
		// candidates with stable IDs and real coordinates.
		$virtual_map_items = array_slice( (array) ( $mymap_context['candidate_items'] ?? array() ), 0, 100 );
		foreach ( $virtual_map_items as $map_item ) {
			$map_item['score'] = self::score( $map_item, $form ) + 8;
			$map_item['mymap_matched'] = true;
			$map_item['acc_unknown'] = true;
			$items[] = $map_item;
		}

		// Pull every map-matched item into the AI candidate list, while still
		// respecting the same hard accessibility/budget constraints used by the
		// normal candidate selector. This makes named map points and route points
		// first-class tour candidates rather than passive metadata.
		$by_id = array();
		foreach ( $items as $candidate_item ) { $by_id[ (int) $candidate_item['id'] ] = true; }
		foreach ( $all_map_items as $map_item ) {
			if ( empty( $mymap_ids[ (int) $map_item['id'] ] ) || isset( $by_id[ (int) $map_item['id'] ] ) ) { continue; }
			if ( self::needs_accessible( $form ) && ! in_array( 'wheelchair', (array) $map_item['accessibility'], true ) ) { continue; }
			if ( 'low' === ( $form['budget_tier'] ?? 'medium' ) && 'high' === (string) $map_item['price_tier'] ) { continue; }
			$map_item['score'] = self::score( $map_item, $form ) + 5;
			$items[] = $map_item;
			$by_id[ (int) $map_item['id'] ] = true;
		}
		foreach ( $items as &$candidate_item ) {
			$cid = (int) $candidate_item['id'];
			if ( isset( $mymap_ids[ $cid ] ) ) {
				$candidate_item['mymap_matched'] = true;
			}
			if ( isset( $route_meta[ $cid ] ) ) {
				$candidate_item['mymap_route_order'] = (int) $route_meta[ $cid ]['order'];
				$candidate_item['mymap_route_map'] = (string) $route_meta[ $cid ]['map'];
				$candidate_item['mymap_route_name'] = (string) $route_meta[ $cid ]['name'];
				$candidate_item['mymap_route_folder'] = (string) $route_meta[ $cid ]['folder'];
			}
		}
		unset( $candidate_item );

		$days     = self::days_count( $form );
		$per_day  = self::stops_per_day( $form );
		$per_day  = min( $per_day, (int) PCT_Planner::get()['strategy']['max_stops_per_proposal'] );
		$per_day  = max( 3, min( 18, $per_day ) );
		$result   = null;
		$provider = 'rules';
		$warning  = '';
		$channel_ai_count = 0;

		if ( PCT_AI::enabled() ) {
			if ( ! PCT_AI::city_db_consent() ) {
				$warning = __( 'AI is configured but "city database → AI" consent is off; the built-in planner was used.', 'personalized-city-tour' );
				PCT_AI_Log::log( array( 'kind' => 'tour', 'provider' => PCT_Settings::get( 'ai_provider' ), 'model' => PCT_Settings::get( 'ai_model' ), 'status' => 'info', 'title' => PCT_AI::request_title( (string) ( $form['name'] ?? '' ), $lang, $city['name'] ), 'error' => $warning ) );
			} else {
				$ai = self::ai_plan( $city, $form, $items, $days, $per_day, $lang, $mymap_context );
				if ( is_wp_error( $ai ) ) {
					$warning = $ai->get_error_message();
					PCT_AI_Log::log( array( 'kind' => 'tour', 'provider' => PCT_Settings::get( 'ai_provider' ), 'model' => PCT_Settings::get( 'ai_model' ), 'status' => 'error', 'title' => PCT_AI::request_title( (string) ( $form['name'] ?? '' ), $lang, $city['name'] ), 'error' => $warning ) );
				} else {
					$ai_channel_rows = (array) ( $ai['channel_candidates'] ?? array() );
					$ai_channel_items = PCT_Channel_Intel::normalize_ai_candidates( $ai_channel_rows, $city, $form );
					if ( $ai_channel_items ) {
						$known_channel_ids = array();
						foreach ( $items as $existing_item ) { $known_channel_ids[(string) ( $existing_item['id'] ?? 0 )] = true; }
						foreach ( $ai_channel_items as $channel_item ) {
							if ( ! isset( $known_channel_ids[(string) $channel_item['id']] ) ) { $items[] = $channel_item; }
							$channel_items[(string) $channel_item['id']] = $channel_item;
						}
						$channel_items = array_values( $channel_items );
					}
					$channel_ai_count = count( $ai_channel_items );
					$result   = PCT_Planner::post_filter( $ai, $items, $form );
					$provider = PCT_AI::sandbox() ? 'sandbox' : PCT_Settings::get( 'ai_provider' );
				}
			}
		} else {
			$warning = __( 'AI is not currently enabled or its API key is unavailable; the built-in planner was used.', 'personalized-city-tour' );
			PCT_AI_Log::log( array( 'kind' => 'tour', 'provider' => PCT_Settings::get( 'ai_provider' ), 'model' => PCT_Settings::get( 'ai_model' ), 'status' => 'error', 'title' => PCT_AI::request_title( (string) ( $form['name'] ?? '' ), $lang, $city['name'] ), 'error' => $warning ) );
		}
		if ( null === $result ) {
			$result = self::rule_based_plan( $city, $form, $items, $days, $per_day );
		}

		// AI selection is advisory; the plugin remains responsible for source
		// coverage. Interest rotation is also a server-side invariant: the AI
		// is told the focus, but the plugin must repair an otherwise valid answer
		// that omitted the requested focus interest.
		$result = self::apply_rotating_interest_focus( $result, $city, $form, $items );

		// If Events or curated My Maps contain compatible candidates, make sure
		// they are not silently ignored by an otherwise valid AI plan.
		$result = self::enforce_curated_sources( $result, $city, $form, $items, $mymap_context, $channel_items );

		// Locationless current Events are not route stops, but they are still
		// valuable discovery signals. Keep them in the proposal as Serendipity
		// candidates so the traveller can investigate/confirm the venue rather
		// than losing a useful current event merely because coordinates are absent.
		$result = self::attach_serendipity_candidates( $result, $items, $form );

		$result = self::canonicalize_route_order( $result, $items, $form );

		$result = self::finalize( $result, $city, $form, $items, $lang );
		$result['source_usage'] = wp_parse_args( (array) ( $result['source_usage'] ?? array() ), array( 'city_channel_candidates_available' => count( $channel_items ) ) );
		$result['source_usage']['city_channel_candidates_available'] = count( $channel_items );
		$result['source_usage']['city_channel_candidates_local'] = (int) $channel_local_count;
		$result['source_usage']['city_channel_candidates_ai'] = (int) $channel_ai_count;
		$result['source_usage']['city_channel_source_report'] = PCT_Channel_Intel::last_report();
		$result['source_usage']['city_channel_research'] = (array) ( $ai['channel_research'] ?? array() );
		$result['source_usage']['interest_priority_mode'] = 'soft_blend';
		$result['source_usage']['interest_weights'] = self::interest_weights( $form );
		$channel_selected = array();
		foreach ( (array) ( $result['days'] ?? array() ) as $d ) {
			foreach ( (array) ( $d['stops'] ?? array() ) as $st ) {
				$item = self::find_item_by_id( $items, (int) ( $st['item_id'] ?? 0 ) );
				if ( $item && ! empty( $item['channel_virtual'] ) ) { $channel_selected[] = array( 'day' => (int) ( $d['day'] ?? 1 ), 'proposal' => (int) ( $d['proposal_number'] ?? 1 ), 'item_id' => (int) $item['id'], 'name' => (string) $item['name'], 'source_url' => (string) ( $item['channel_source_url'] ?? '' ), 'type' => (string) ( $item['type'] ?? '' ) ); }
			}
		}
		$channel_report = PCT_Channel_Intel::last_report();
		$channel_sources = array();
		foreach ( (array) ( $channel_report['sources'] ?? array() ) as $source_row ) {
			$channel_sources[] = array(
				'label' => (string) ( $source_row['label'] ?? '' ),
				'url' => (string) ( $source_row['url'] ?? '' ),
				'fetched' => ! empty( $source_row['fetched'] ),
				'cache_hit' => ! empty( $source_row['cache_hit'] ),
				'http_code' => (int) ( $source_row['http_code'] ?? 0 ),
				'fetch_url' => (string) ( $source_row['fetch_url'] ?? '' ),
				'raw_candidates' => (int) ( $source_row['raw_candidates'] ?? 0 ),
				'accepted_candidates' => (int) ( $source_row['accepted_candidates'] ?? 0 ),
				'locationless_accepted_candidates' => (int) ( $source_row['locationless_accepted_candidates'] ?? 0 ),
				'route_ready_candidates' => (int) ( $source_row['route_ready_candidates'] ?? 0 ),
				'rejected_candidates' => (int) ( $source_row['rejected_candidates'] ?? 0 ),
				'rejection_reasons' => (array) ( $source_row['rejection_reasons'] ?? array() ),
				'error' => (string) ( $source_row['error'] ?? '' ),
			);
		}
		PCT_AI_Log::log(
			array(
				'kind' => 'channel',
				'provider' => 'public-web',
				'model' => 'city-channel-intel',
				'status' => count( $channel_items ) > 0 ? 'ok' : 'info',
				'duration_ms' => 0,
				'title' => 'City Channel research · ' . $city['name'],
				'force_full' => true,
				'request' => array(
					'configured_sources' => array_map( function ( $c ) { return array( 'label' => (string) ( $c['label'] ?? '' ), 'url' => esc_url_raw( (string) ( $c['url'] ?? '' ) ) ); }, (array) $city['channels'] ),
					'local_candidates' => $channel_local_count,
					'ai_candidates' => $channel_ai_count,
					'source_report' => $channel_report,
					'source_snapshots' => PCT_Channel_Intel::source_contexts(),
				),
				'response' => array(
					'accepted_candidates' => count( $channel_items ),
					'route_ready_candidates' => (int) ( $channel_report['route_ready_candidates'] ?? 0 ),
					'rejected_candidates' => (int) ( $channel_report['rejected_candidates'] ?? 0 ),
					'channel_research' => (array) ( $ai['channel_research'] ?? array() ),
					'proposals_with_city_channel' => (int) ( $result['source_usage']['proposals_with_city_channel'] ?? 0 ),
					'selected' => $channel_selected,
					'sources' => $channel_sources,
				),
				'error' => count( $channel_items ) ? '' : 'No accepted City Channel candidates were available for this generation.',
			)
		);

		$result['provider']     = $provider;
		$result['generated_at'] = current_time( 'mysql' );
		$result['lang']         = $lang;
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
				return max( 3, $base - 2 );
			case 'fast':
				return min( 18, $base + 2 );
			default:
				return $base;
		}
	}

	public static function needs_accessible( array $form ) {
		$lim = (array) ( $form['mobility_limitations'] ?? array() );
		return in_array( 'wheelchair', $lim, true );
	}

	private static function interest_weights( array $form ) {
		$interests = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $form['interests'] ?? array() ) ) ) ) );
		if ( empty( $interests ) ) { return array(); }
		// Soft priority profile: the first interest dominates, but secondary
		// interests remain materially selectable. This is intentionally not an
		// exclusion rule; route composition will later decide how much support
		// each interest deserves.
		$pw = PCT_Planner::get()['weights'];
		$rank_weights = array(
			1.00 * (float) $pw['interest_focus_weight'],
			(float) $pw['secondary_interest_weight'],
			(float) $pw['secondary_interest_weight'] * 0.67,
			(float) $pw['secondary_interest_weight'] * 0.42,
			(float) $pw['secondary_interest_weight'] * 0.30,
			(float) $pw['secondary_interest_weight'] * 0.24,
		);
		$out = array();
		foreach ( $interests as $i => $slug ) {
			$out[ $slug ] = 12 * ( $rank_weights[ $i ] ?? 0.18 );
		}
		return $out;
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

	/**
	 * Concrete dates used for each itinerary day. Flexible requests are mapped
	 * from today forward so date-bound events can still be matched safely.
	 */
	private static function travel_days( array $form, $days ) {
		$out = array();
		$start = '';
		if ( 'custom' === ( $form['dates_mode'] ?? '' ) && ! empty( $form['date_start'] ) ) {
			$start = $form['date_start'];
		} else {
			$start = current_time( 'Y-m-d' );
		}
		$ts = strtotime( $start . ' 00:00:00' );
		for ( $i = 0; $i < max( 1, (int) $days ); $i++ ) {
			$out[] = wp_date( 'Y-m-d', $ts + $i * DAY_IN_SECONDS, wp_timezone() );
		}
		return $out;
	}

	private static function travel_day_date( array $form, $day_number ) {
		$days = self::travel_days( $form, max( 1, (int) $day_number ) );
		return $days[ max( 0, (int) $day_number - 1 ) ] ?? '';
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
			if ( 'event' === ( $item['type'] ?? '' ) && ! self::valid_scheduled_event_for_window( $item, $from, $to ) ) {
				continue;
			}
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

		$max = min( 120, max( 60, (int) PCT_Settings::get( 'ai_max_items', 60 ) ) );
		$top = array_slice( $filtered, 0, $max );

		/*
		 * Events are a first-class tour source. Do not let a score/cap cutoff
		 * remove every date-matched Event before the AI ever sees it.
		 */
		$seen = array();
		foreach ( $top as $item ) {
			$seen[ (int) $item['id'] ] = true;
		}
		foreach ( $filtered as $item ) {
			if ( 'event' !== ( $item['type'] ?? '' ) || isset( $seen[ (int) $item['id'] ] ) ) {
				continue;
			}
			$top[] = $item;
			$seen[ (int) $item['id'] ] = true;
			if ( count( $top ) >= 160 ) {
				break;
			}
		}
		return $top;
	}

	private static function score( array $item, array $form ) {
		$weights = self::interest_weights( $form );
		$pw      = PCT_Planner::get()['weights'];
		$score   = 0.0;
		foreach ( (array) ( $item['categories'] ?? array() ) as $cat ) {
			if ( isset( $weights[ $cat ] ) ) {
				$score += $weights[ $cat ];
			}
		}
		if ( ! empty( $item['channel_virtual'] ) ) {
			// Current public-source discoveries are useful, but remain below
			// authoritative curated City Data/My Maps unless their personal
			// relevance is stronger.
			$score += ( 'event' === ( $item['type'] ?? '' ) ? 2.5 : 1.5 ) + (float) $pw['channel_freshness_bonus'];
		}
		if ( ! empty( $item['channel_ai_verified'] ) ) { $score += 1.0; }
		if ( ! empty( $item['location_status'] ) && 'verified' === $item['location_status'] ) { $score += (float) $pw['location_verified_bonus']; }
		if ( ! empty( $item['channel_source_url'] ) ) { $score += (float) $pw['source_diversity_bonus'] * 0.25; }
		if ( ! empty( $item['channel_virtual'] ) && 'event' === ( $item['type'] ?? '' ) && ( empty( $item['lat'] ) || empty( $item['lng'] ) ) ) { $score += (float) $pw['serendipity_bonus']; }
		if ( empty( $weights ) ) {
			$score += 3;
		}

		$local = (int) ( $form['local_experience'] ?? 5 );
		if ( in_array( 'social', $item['categories'], true ) || in_array( 'markets', $item['categories'], true ) ) {
			$score += $local / 4;
		}
		if ( 'event' === $item['type'] ) {
			$score += 2 + $local / 5 + (float) $pw['event_relevance_bonus']; // Live events are inherently local.
		}
		if ( ! empty( $item['mymap_matched'] ) ) {
			$score += (float) $pw['mymap_bonus']; // Curated city-map points are preferred when otherwise compatible.
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
			$score += (float) $pw['time_fit_bonus'];
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
		$per_day = max( 3, min( 18, min( (int) $per_day, (int) PCT_Planner::get()['strategy']['max_stops_per_proposal'] ) ) );
		$pool      = $items;
		$plan_days = array();
		$weights   = self::interest_weights( $form );
		$pw        = PCT_Planner::get()['weights'];
		$proposal_windows = self::proposal_windows( $form );

		for ( $d = 1; $d <= $days; $d++ ) {
			for ( $p = 1; $p <= 4; $p++ ) {
				if ( empty( $pool ) ) {
					$pool = $items;
				}
				$selected = array();
				$anchor_index = empty( $pool ) ? 0 : ( ( $p - 1 ) * 2 ) % count( $pool );
				$anchor = $pool[ $anchor_index ] ?? array_shift( $pool );
				if ( isset( $pool[ $anchor_index ] ) ) { unset( $pool[ $anchor_index ] ); $pool = array_values( $pool ); }
				if ( ! $anchor ) {
					continue;
				}
				$selected[] = $anchor;

				while ( count( $selected ) < $per_day && ! empty( $pool ) ) {
					$last  = end( $selected );
					$best  = null;
					$bestv = -INF;
					foreach ( $pool as $idx => $cand ) {
						$dist  = PCT_Data::distance( $last['lat'], $last['lng'], $cand['lat'], $cand['lng'] );
						$value = $cand['score'] - min( (float) $pw['max_distance_km'], $dist * (float) $pw['distance_penalty'] * max( 0.25, (float) $pw['route_compactness_penalty'] ) );
						if ( array_intersect( $cand['categories'], $last['categories'] ) ) {
							$value -= (float) $pw['variety_penalty'];
						}
						if ( $value > $bestv ) {
							$bestv = $value;
							$best  = $idx;
						}
					}
					if ( null === $best ) {
						break;
					}
					$selected[] = $pool[ $best ];
					unset( $pool[ $best ] );
					$pool = array_values( $pool );
				}

				$selected = self::order_route( $selected );
				$times = self::slot_times_for_window( $form, $per_day, $proposal_windows[ $p - 1 ] );
				$stops = array();
				foreach ( $selected as $i => $item ) {
					$stops[] = array(
						'item_id' => $item['id'],
						'time'    => $times[ $i ] ?? '',
						'note'    => self::stop_note( $item, $form ),
					);
				}

				$alternatives = array();
				foreach ( array_slice( $pool, 0, 2 ) as $alt ) {
					$alternatives[] = array(
						'item_id' => $alt['id'],
						'note'    => __( 'Swap this stop in if you prefer it or need a shorter visit.', 'personalized-city-tour' ),
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
				$window = $proposal_windows[ $p - 1 ];

				$plan_days[] = array(
					'day'             => $d,
					'proposal_number' => $p,
					'proposal_type'   => $window['type'],
					/* translators: 1: day number, 2: proposal number, 3: theme */
					'title'           => sprintf( __( 'Day %1$d — Option %2$d · %3$s', 'personalized-city-tour' ), $d, $p, $theme ),
					'summary'         => self::proposal_summary( $selected, $form, $city, $window ),
					'stops'           => $stops,
					'alternatives'    => $alternatives,
				);
			}
		}

		return array( 'days' => $plan_days );
	}

	/**
	 * Preferred half-day windows. They remain inside the planner's global
	 * day window and adapt to the traveller's preferred times when possible.
	 */
	private static function proposal_windows( array $form ) {
		$conf = PCT_Planner::get()['constraints'];
		$start = $conf['day_start'];
		$end   = $conf['day_end'];
		$pref  = array_values( (array) ( $form['preferred_times'] ?? array() ) );
		$windows = array();
		$add = function( $from, $to, $type ) use ( &$windows, $start, $end ) {
			$from = max( $start, $from );
			$to   = min( $end, $to );
			if ( strcmp( $to, $from ) <= 0 ) { $from = $start; $to = $end; $type = 'flexible'; }
			$windows[] = array( 'start' => $from, 'end' => $to, 'type' => $type );
		};
		if ( in_array( 'morning', $pref, true ) || empty( $pref ) ) { $add( '09:00', '13:30', 'morning' ); }
		if ( in_array( 'afternoon', $pref, true ) || empty( $pref ) ) { $add( '14:00', '18:30', 'afternoon' ); }
		if ( in_array( 'evening', $pref, true ) || in_array( 'night', $pref, true ) || empty( $pref ) ) { $add( '17:00', '21:30', 'evening' ); }
		if ( count( $windows ) < 4 ) {
			$base = $windows[0] ?? array( 'start' => $start, 'end' => $end, 'type' => 'flexible' );
			$add( $base['start'], $base['end'], $base['type'] );
		}
		while ( count( $windows ) < 4 ) { $windows[] = $windows[ count( $windows ) % max( 1, count( $windows ) ) ]; }
		return array_slice( $windows, 0, 4 );
	}

	private static function slot_times_for_window( array $form, $per_day, array $window ) {
		$count = max( 1, (int) $per_day );
		$from = strtotime( '2000-01-01 ' . $window['start'] );
		$to   = strtotime( '2000-01-01 ' . $window['end'] );
		if ( $to <= $from ) {
			return array();
		}
		$step = ( $to - $from ) / max( 1, $count );
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $from + (int) round( $i * $step );
			$out[] = wp_date( 'H:i', $t, wp_timezone() );
		}
		return $out;
	}

	private static function proposal_summary( array $selected, array $form, array $city, array $window ) {
		$names = wp_list_pluck( $selected, 'name' );
		$summary = sprintf(
			/* translators: 1: time window, 2: stop names */
			__( 'A friendly %1$s half-day in %2$s, moving at your preferred pace and focusing on %3$s.', 'personalized-city-tour' ),
			$window['type'],
			$city['name'],
			implode( ', ', $names )
		);
		$modes = (array) ( $form['mobility_modes'] ?? array() );
		if ( in_array( 'walking', $modes, true ) ) {
			$summary .= ' ' . __( 'The stops are arranged to keep the route practical for walking.', 'personalized-city-tour' );
		} elseif ( in_array( 'public_transport', $modes, true ) ) {
			$summary .= ' ' . __( 'Public transport is the preferred connection between stops.', 'personalized-city-tour' );
		}
		return $summary;
	}

	/**
	 * Deterministic geographic pre-optimizer. It scales to the 18-stop ceiling
	 * with nearest-neighbour construction followed by bounded 2-opt improvement,
	 * avoiding factorial permutation cost while reducing long/crossing legs. This is
	 * a geographic pre-optimizer; the selected sequence is then passed to the
	 * configured routing provider by the public map layer. Curated My Maps route
	 * order is preserved elsewhere and therefore remains authoritative.
	 */
	private static function order_route( array $stops ) {
		$stops = array_values( $stops );
		$n = count( $stops );
		if ( $n < 3 ) { return $stops; }
		$anchor = array_shift( $stops );
		$ordered = array( $anchor );
		$current = $anchor;
		$remaining = $stops;
		while ( ! empty( $remaining ) ) {
			$best_i = null; $best_d = INF;
			foreach ( $remaining as $i => $candidate ) {
				if ( ! isset( $current['lat'], $current['lng'], $candidate['lat'], $candidate['lng'] ) ) { continue; }
				$d = PCT_Data::distance( (float) $current['lat'], (float) $current['lng'], (float) $candidate['lat'], (float) $candidate['lng'] );
				if ( $d < $best_d ) { $best_d = $d; $best_i = $i; }
			}
			if ( null === $best_i ) { $ordered = array_merge( $ordered, array_values( $remaining ) ); break; }
			$current = $remaining[ $best_i ];
			$ordered[] = $current;
			unset( $remaining[ $best_i ] );
			$remaining = array_values( $remaining );
		}
		// 2-opt improvement keeps the computation cheap even for 18 stops and
		// substantially reduces crossing/zig-zagging introduced by greedy order.
		$distance = function( $a, $b ) {
			if ( ! isset( $a['lat'], $a['lng'], $b['lat'], $b['lng'] ) ) { return INF; }
			return PCT_Data::distance( (float) $a['lat'], (float) $a['lng'], (float) $b['lat'], (float) $b['lng'] );
		};
		$improved = true; $guard = 0;
		while ( $improved && $guard++ < 8 ) {
			$improved = false;
			for ( $i = 1; $i < count( $ordered ) - 2; $i++ ) {
				for ( $j = $i + 1; $j < count( $ordered ) - 1; $j++ ) {
					$old = $distance( $ordered[ $i - 1 ], $ordered[ $i ] ) + $distance( $ordered[ $j ], $ordered[ $j + 1 ] );
					$new = $distance( $ordered[ $i - 1 ], $ordered[ $j ] ) + $distance( $ordered[ $i ], $ordered[ $j + 1 ] );
					if ( $new + 0.0001 < $old ) {
						$segment = array_reverse( array_slice( $ordered, $i, $j - $i + 1 ) );
						array_splice( $ordered, $i, $j - $i + 1, $segment );
						$improved = true;
					}
				}
			}
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

	/**
	 * City-database fields the admin may allow in the AI extract.
	 */
	public static function extract_fields() {
		return array(
			'name'          => __( 'Name', 'personalized-city-tour' ),
			'type'          => __( 'Type (place/event)', 'personalized-city-tour' ),
			'categories'    => __( 'Categories', 'personalized-city-tour' ),
			'tags'          => __( 'Tags', 'personalized-city-tour' ),
			'summary'       => __( 'Summary text', 'personalized-city-tour' ),
			'address'       => __( 'Address', 'personalized-city-tour' ),
			'coordinates'   => __( 'Coordinates', 'personalized-city-tour' ),
			'link'          => __( 'Official / event links', 'personalized-city-tour' ),
			'ar'            => __( 'Augmented reality service', 'personalized-city-tour' ),
			'images'        => __( 'Featured image', 'personalized-city-tour' ),
			'price_tier'    => __( 'Price tier', 'personalized-city-tour' ),
			'accessibility' => __( 'Accessibility', 'personalized-city-tour' ),
			'duration'      => __( 'Typical duration', 'personalized-city-tour' ),
			'best_time'     => __( 'Best time of day', 'personalized-city-tour' ),
			'hours'         => __( 'Opening hours', 'personalized-city-tour' ),
			'start'         => __( 'Event start', 'personalized-city-tour' ),
			'end'           => __( 'Event end', 'personalized-city-tour' ),
			'venue'         => __( 'Venue', 'personalized-city-tour' ),
			'organizer'     => __( 'Organizer name', 'personalized-city-tour' ),
			'channels'      => __( 'City channels & My Maps (city level)', 'personalized-city-tour' ),
			'socials'       => __( 'Social media links', 'personalized-city-tour' ),
		);
	}

	/**
	 * Scoped extract of one city item containing only admin-allowed fields.
	 */
	public static function extract_item( array $item, array $allowed ) {
		$row = array( 'id' => $item['id'] );
		$map = array(
			'name'          => 'name',
			'type'          => 'type',
			'categories'    => 'categories',
			'tags'          => 'tags',
			'summary'       => 'summary',
			'address'       => 'address',
			'link'          => 'link',
			'ar'            => 'ar_url',
			'images'        => 'image',
			'price_tier'    => 'price',
			'accessibility' => 'access',
			'duration'      => 'duration',
			'best_time'     => 'best_time',
			'hours'         => 'hours',
			'socials'       => 'socials',
		);
		if ( ! empty( $item['channel_virtual'] ) ) {
			$row['source'] = 'city_channel_online';
			$row['channel_virtual'] = true;
			$row['channel_source_url'] = esc_url_raw( (string) ( $item['channel_source_url'] ?? '' ) );
		}
		if ( ! empty( $item['channel_virtual'] ) ) { $row['channel_location_status'] = (string) ( $item['location_status'] ?? 'unknown' ); }
		if ( ! empty( $item['mymap_matched'] ) ) {
			$row['curated_mymap'] = true;
		}
		if ( ! empty( $item['mymap_virtual'] ) ) {
			// KML points are promoted to the same compact record shape as City Data
			// places. This makes them unambiguous to the AI instead of leaving them
			// only inside a secondary My Maps context block.
			$row['curated_mymap'] = true;
			$row['curated_mymap_virtual'] = true;
			$row['source'] = 'google_mymaps_kml';
			$row['type'] = 'place';
			$row['name'] = (string) ( $item['name'] ?? '' );
			$row['categories'] = array_values( (array) ( $item['categories'] ?? array() ) );
			$row['tags'] = array_values( (array) ( $item['tags'] ?? array() ) );
			$row['summary'] = (string) ( $item['summary'] ?? '' );
			$row['lat'] = (float) ( $item['lat'] ?? 0 );
			$row['lng'] = (float) ( $item['lng'] ?? 0 );
			$row['gmaps_url'] = (string) ( $item['gmaps_url'] ?? '' );
			$row['mymap_label'] = (string) ( $item['mymap_label'] ?? '' );
			$row['mymap_folder'] = (string) ( $item['mymap_folder'] ?? '' );
			$row['mymap_description'] = (string) ( $item['mymap_description'] ?? '' );
			$row['mymap_source'] = 'KML';
		}
		if ( ! empty( $item['mymap_route_order'] ) || 0 === (int) ( $item['mymap_route_order'] ?? -1 ) ) {
			$row['mymap_route_order'] = (int) $item['mymap_route_order'];
			$row['mymap_route_map'] = (string) ( $item['mymap_route_map'] ?? '' );
			$row['mymap_route_name'] = (string) ( $item['mymap_route_name'] ?? '' );
			$row['mymap_route_folder'] = (string) ( $item['mymap_route_folder'] ?? '' );
		}
		foreach ( $map as $field => $key ) {
			if ( in_array( $field, $allowed, true ) && isset( $item[ $field ] ) && '' !== $item[ $field ] && array() !== $item[ $field ] ) {
				$row[ $key ] = $item[ $field ];
			}
		}
		if ( in_array( 'coordinates', $allowed, true ) && ! empty( $item['lat'] ) ) {
			$row['lat'] = $item['lat'];
			$row['lng'] = $item['lng'];
			if ( ! empty( $item['gmaps_url'] ) ) {
				$row['gmaps_url'] = $item['gmaps_url'];
			}
		}
		if ( in_array( 'images', $allowed, true ) && ! empty( $item['image'] ) ) {
			$row['image'] = $item['image'];
		}
		if ( 'event' === $item['type'] ) {
			foreach ( array( 'start' => 'starts', 'end' => 'ends', 'venue' => 'venue', 'organizer' => 'organizer' ) as $field => $key ) {
				if ( in_array( $field, $allowed, true ) && ! empty( $item[ $field ] ) ) {
					$row[ $key ] = $item[ $field ];
				}
			}
		}
		return $row;
	}

	/**
	 * Build the structured payload sent to the AI (also used for QA samples).
	 */
	public static function ai_payload( array $city, array $form, array $items, $days, $per_day, $lang, array $mymap_context = array() ) {
		// Use the exact server-side context already built for this generation.
		// Re-fetching KML here could create a different candidate set or route
		// order between the planner and the AI payload.
		if ( empty( $mymap_context ) ) {
			$mymap_context = PCT_Data::curated_mymap_context( $city['id'], $items );
		}
		$schema  = PCT_Form_Builder::get_schema();
		$fields  = PCT_Form_Builder::field_map( $schema );
		$profile = array();
		$share_contact = (bool) PCT_Settings::get( 'ai_share_contact', 0 );
		$share_name    = (bool) PCT_Settings::get( 'ai_share_name', 0 );
		foreach ( $form as $key => $value ) {
			if ( in_array( $key, array( 'email', 'phone' ), true ) && ! $share_contact ) {
				continue;
			}
			if ( 'name' === $key && ! $share_name ) {
				continue;
			}
			$label           = isset( $fields[ $key ] ) ? $fields[ $key ]['label'] : $key;
			$profile[ $key ] = array(
				'question' => $label,
				'answer'   => isset( $fields[ $key ] ) ? PCT_Form_Builder::display_value( $fields[ $key ], $value ) : $value,
			);
		}

		$allowed = (array) PCT_Settings::get( 'ai_allowed_fields', array() );
		$compact = array();
		foreach ( $items as $item ) {
			$compact[] = self::extract_item( $item, $allowed );
		}
		$curated_city_data = array();
		foreach ( (array) ( $mymap_context['candidate_items'] ?? array() ) as $map_item ) {
			$curated_city_data[] = self::extract_item( $map_item, array( 'name', 'type', 'categories', 'tags', 'summary', 'coordinates', 'link', 'images', 'accessibility', 'duration', 'best_time', 'hours' ) );
		}

		$city_block = array(
			'id'          => $city['id'],
			'name'        => $city['name'],
			'country'     => $city['country'],
			'description' => wp_trim_words( $city['description'], 120 ),
			'timezone'    => $city['timezone'],
			'coordinates' => array( 'lat' => $city['lat'], 'lng' => $city['lng'] ),
			'google_maps' => $city['gmaps_url'],
			'channels'    => array_map(
				function ( $c ) {
					return array( 'label' => $c['label'], 'url' => $c['url'] );
				},
				(array) $city['channels']
			),
			'curated_maps' => array_map(
				function ( $m ) {
					return array( 'label' => $m['label'], 'url' => $m['url'] );
				},
				(array) $city['mymaps']
			),
		);

		$title = PCT_AI::request_title( (string) ( $form['name'] ?? '' ), $lang, $city['name'] );


		$links = PCT_AI::city_db_consent() ? PCT_Sync::ai_links() : array();
		$from  = self::date_window( $form )[0];
		$to    = self::date_window( $form )[1];
		$channel_research = array(
			'configured_sources' => array_map( function ( $c ) { return array( 'label' => (string) ( $c['label'] ?? '' ), 'url' => esc_url_raw( (string) ( $c['url'] ?? '' ) ) ); }, (array) $city['channels'] ),
			'source_report' => PCT_Channel_Intel::last_report(),
			'source_snapshots' => PCT_Channel_Intel::source_contexts(),
			'prefetched_candidates' => array_values( array_map( function ( $item ) { return self::extract_item( $item, array( 'name','type','categories','tags','summary','address','coordinates','link','price_tier','duration','start','end','venue','organizer','channels' ) ); }, array_slice( array_values( array_filter( (array) $items, function ( $item ) { return ! empty( $item['channel_virtual'] ); } ) ), 0, 32 ) ) ),
		);
		$payload = array(
			'title'        => $title,
			'curated_mymaps' => $mymap_context,
			'curated_city_data_items' => $curated_city_data,
			'live_city_channel_candidates' => array_values( array_filter( $compact, function( $row ) { return ! empty( $row['channel_virtual'] ) || ! empty( $row['channel_source_url'] ); } ) ),
			'channel_research_sources' => array_map( function ( $c ) { return array( 'label' => (string) ( $c['label'] ?? '' ), 'url' => esc_url_raw( (string) ( $c['url'] ?? '' ) ), 'must_check' => true ); }, (array) $city['channels'] ),
			'channel_research_tasks' => array_map( function ( $c ) use ( $city, $from ) { $u = esc_url_raw( (string) ( $c['url'] ?? '' ) ); return array( 'label' => (string) ( $c['label'] ?? '' ), 'url' => $u, 'query' => (string) ( $c['label'] ?? '' ) . ' ' . $city['name'] . ' ' . $from . ' current event place', 'instruction' => 'Inspect this exact public source first. If its live page is incomplete, use web search to locate the current public posts/pages for the same source/handle. Do not use stale results.' ); }, (array) $city['channels'] ),
			'channel_source_snapshots' => PCT_Channel_Intel::source_contexts(),
			'route_planning' => array(
				'note' => 'The plugin, not the AI, builds the final map route. Select stop IDs and preserve any supplied route sequence order; the plugin resolves coordinates and routing.',
				'curated_route_sequences' => array_values( (array) ( $mymap_context['route_sequences'] ?? array() ) ),
			),
			'consent'      => array(
				'city_db_to_ai'   => PCT_AI::city_db_consent(),
				'allowed_fields'  => $allowed,
				'contact_shared'  => $share_contact,
				'export_links'    => array_values( array_unique( array_column( $links, 'source' ) ) ),
			),
			'instructions' => array(
				'days'                    => $days,
				'proposals_per_day'       => 4,
				'proposal_focus_interests' => self::proposal_focus_interests( $form ),
				'interest_weights' => self::interest_weights( $form ),
				'interest_rotation_enabled' => ! empty( PCT_Planner::get()['strategy']['interest_rotation'] ),
				'interest_priority_mode' => 'soft_blend',
				'proposal_duration'       => 'half_day',
				'stops_per_proposal'      => max( 3, min( 18, $per_day ) ),
				'route_quality_policy'     => 'Use only as many stops as can fit the half-day window with realistic visit durations, opening hours and practical route travel. 18 is a ceiling, not a target.',
				'alternatives_per_proposal' => 1,
				'diversity'               => array( 'interest_focus', 'travel_style', 'neighbourhood_or_route', 'pace_or_time' ),
				'language'                => $lang,
				'today'                   => current_time( 'Y-m-d' ),
				'calendar'                => PCT_I18n::calendar_for( $lang ),
				'travel_window'           => array( 'from' => $from, 'to' => $to ),
				'travel_days'             => self::travel_days( $form, $days ),
				'preferred_times'         => array_values( (array) ( $form['preferred_times'] ?? array() ) ),
				'planner_day_window'      => PCT_Planner::get()['constraints'],
				'quick_search_hints'      => 'Do not generate generic web-search links. Quick-search links are assembled by the plugin from event social links and city channels. When web search is enabled, actively inspect the configured City Channel sources for current events/places. Return any high-confidence current event/place discoveries in channel_candidates so the plugin can normalize them into first-class City-Data-like virtual stops. Configured City Channel URLs are the authoritative research targets. Do not privilege, name, or prefer any particular domain. Inspect each configured public source when web search is available; public social pages may be partially readable. Do not invent or use private/login-only content.',
			),
			'planner_rules' => PCT_Planner::prompt_rules( $form ),
			'planner_strategy' => PCT_Planner::get()['strategy'],
			'city'          => $city_block,
			'traveller'     => $profile,
			'items'         => $compact,
			'data_policy'   => array(
				'never_invent_item'      => true,
				'allow_curated_mymap_virtual_items' => true,
				'curated_kml_items_are_city_data' => true,
				'never_invent_link'      => true,
				'use_item_ids_only'     => true,
				'use_current_items'     => true,
				'respect_event_dates'   => true,
				'respect_opening_hours' => true,
				'route_from_coordinates'=> true,
				'curated_mymap_is_route_source' => true,
				'city_channels_are_current_enrichment' => true,
				'channel_ai_candidates_must_be_normalized_by_plugin' => true,
				'channel_candidate_source_must_match_configured_channel' => true,
				'never_treat_unverified_channel_text_as_fact' => true,
				'preserve_curated_route_order' => true,
				'plugin_builds_final_route' => true,
				'diversify_options'     => true,
				'minimize_repeated_stops' => true,
			),
		);
		if ( $links ) {
			$payload['city_database_files'] = array(
				'note'  => 'These are optional access references only. Do not fetch or invent additional places from them; the plugin has already supplied the relevant current city items.',
				'files' => $links,
			);
		}
		return $payload;
	}

	/**
	 * Load the newest city export from uploads, with a private Drive fallback.
	 * Only the selected city is returned to the model to keep prompts small.
	 */
	private static function latest_city_context( $city_id, array $items = array() ) {
		$row = PCT_Drive::latest( 'cities' );
		$data = null;
		if ( $row && ! empty( $row['path'] ) && file_exists( $row['path'] ) ) {
			$raw = file_get_contents( $row['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$data = json_decode( (string) $raw, true );
		}
		if ( ! is_array( $data ) && $row && ! empty( $row['drive_id'] ) ) {
			$data = PCT_Drive::download_json( $row['drive_id'] );
		}
		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			return array();
		}
		$records = isset( $data['records'] ) && is_array( $data['records'] ) ? $data['records'] : array();
		foreach ( $records as $record ) {
			if ( (int) ( $record['id'] ?? 0 ) !== (int) $city_id ) {
				continue;
			}
			// Limit duplicate data already sent as the compact item list, but
			// preserve the authoritative city, place and event records.
			$ids = array();
			foreach ( $items as $item ) {
				$ids[ (int) ( $item['id'] ?? 0 ) ] = true;
			}
			if ( ! empty( $ids ) ) {
				foreach ( array( 'places', 'events' ) as $bucket ) {
					if ( isset( $record[ $bucket ] ) && is_array( $record[ $bucket ] ) ) {
						$record[ $bucket ] = array_values( array_filter( $record[ $bucket ], function ( $item ) use ( $ids ) {
							return isset( $ids[ (int) ( $item['id'] ?? 0 ) ] );
						} ) );
					}
				}
			}
			return array(
				'exported' => $data['exported'] ?? '',
				'city'     => $record,
			);
		}
		return array();
	}

	public static function system_prompt( array $city, $lang ) {
		$template = trim( (string) PCT_Settings::get( 'ai_prompt_template', '' ) );
		if ( '' === $template ) {
			$template = 'You are an experienced local tour planner for {city}. '
				. 'Create safe, realistic, friendly personalized half-day city-tour options using the supplied current City Data, date-matched Events, and curated Google My Maps candidates. '
				. 'Never invent a place, event, coordinate, opening hour, date, price, social link or official link. Every stop must reference an item_id from the supplied current item list. Curated Google My Maps points are valid supplied items even when their item_id is a virtual map ID. '
				. 'For EVERY travel day create exactly FOUR distinct proposals: proposal 1, proposal 2, proposal 3 and proposal 4. Each proposal must fit a half-day, normally 3-5 hours, with up to 18 meaningful stops when the traveller\'s pace, visit durations, opening hours and route feasibility genuinely support them. Never fill to 18 merely to reach a quota; prefer fewer, better stops when necessary. '
				. 'Use the traveller date window and preferred times. Match event dates and event start/end times exactly; never schedule an event outside its date/time. Match opening hours and realistic visit durations. '
				. 'Respect travel style, ranked interests, budget, dietary needs, accessibility, pace, mobility mode and all planner_rules. '
				. 'Each proposal has a deterministic focus interest supplied in instructions.proposal_focus_interests. Proposal 1 uses the first ranked interest, proposal 2 the second, proposal 3 the third, proposal 4 the fourth, cycling back to the first when needed. The focus interest is the dominant theme, not an exclusion filter: keep it visibly stronger than secondary interests, while allowing 1-2 supporting stops from the traveller\'s other ranked interests when they improve the route or experience. Do not delete secondary interests merely because another interest is the focus. '
				. 'The summary for every proposal must be a short warm narrative that helps the traveller choose the option, not a technical list. Make the four options genuinely different: vary the dominant interest/style, route/area, pace or time emphasis, and avoid repeating the same stops except when the city data is too limited. Stop notes should be practical and specific. '
				. 'Prefer stored official/event links and stored social links. If no official link exists, do not invent one; the plugin will hide the contact action. '
				. 'A current Event without verified coordinates can still be valuable: do not discard it. Evaluate it as Serendipity when its date/time and source confidence are strong, but never use it as a route/map stop until location is verified. Return it in the proposal serendipity field with a clear reason/confirmation note. '
				. 'Public City Channel candidates are a mandatory current-research layer, not an optional hint. For EVERY configured City Channel source, first inspect the exact configured URL and then, when the page is incomplete (especially public Telegram/Instagram pages), perform a web search using the exact handle/domain plus the city and requested travel date. Do not stop at the first source that yields results. Return one channel_research record for EVERY configured source stating whether it was checked, whether a current relevant item was found, and why. Return every high-confidence current discovery in channel_candidates. Each candidate must have the exact source_url of the configured source or a same-host direct public item URL, name, type, summary, start/end if known, venue, coordinates when discoverable, categories/tags, duration, price tier and confidence. Never invent missing facts; use empty strings or 0 coordinates when unknown. The plugin will reject low-confidence candidates, verify that the source belongs to a configured channel, match or geocode the candidate, assign the stable item_id and convert it to the same City-Data-like virtual-stop format used for My Maps/KML. When a candidate is accepted, it should be considered a real selectable source item for the final tour. '
			. 'The curated_city_data_items block is authoritative City Data-like input: every KML point is a selectable place with its exact numeric item_id and coordinates. Use those IDs directly in stops; never substitute arbitrary web places for them. '
			. 'Use the supplied curated Google My Maps feature data as an active route-planning source. The plugin converts every usable KML point into a City Data-like virtual item with a stable item_id, type, name, summary, categories, tags, coordinates and curated-map metadata. Treat those virtual items exactly like ordinary City Data places: they are selectable stops, may be used in the text proposal, and their item_id must be returned in stops. When a feature has a matched_item_id, prefer that item when it is compatible with the traveller\'s date, time, accessibility, budget and interests, and include at least one compatible matched item in each proposal whenever such a feature exists. If a feature has a candidate_item_id, use that virtual item as a real selectable stop with authoritative coordinates and supplied description. If a route feature supplies route_item_ids, use those IDs in that order when building a route proposal whenever compatible. Never invent a place to replace a supplied My Maps point. '
				. 'Write ALL user-facing generated text in the selected language, not merely the labels. The selected language is {lang_name} ({lang}); if it is Persian or Arabic, use natural RTL-language prose. Never mix English UI labels into the generated tour text. When web search is enabled, use it only to enrich current facts, opening status, official links and practical notes for supplied city items; do not replace the curated database with arbitrary web results.';
		}
		$lang_meta = PCT_I18n::languages()[ $lang ] ?? array( 'english' => $lang );
		$lang_name = (string) ( $lang_meta['english'] ?? $lang );
		$system = str_replace(
			array( '{city}', '{country}', '{lang}', '{lang_name}' ),
			array( $city['name'] . ( $city['country'] ? ', ' . $city['country'] : '' ), $city['country'], $lang, $lang_name ),
			$template
		);
		$system .= ' Mandatory quality rules: exactly four distinct half-day proposals for every requested day; '
			. 'use only supplied item_ids (including supplied virtual My Maps item_ids); never invent links or facts; match each stop time to known opening hours/event time when available; '
			. 'match event dates to the requested day; keep proposals geographically coherent; include a warm concise whole-proposal summary; '
			. 'Interest priority is a soft weighting: rank-1 interest should dominate the option, but rank-2/rank-3 interests may appear as supporting or experience stops when they are route-compatible. Never treat the focus interest as a hard category filter. '
			. 'include as many as 18 stops per proposal when enough suitable city data exists and the half-day remains realistic; never invent stops and never add filler just to reach the ceiling; use the traveller\'s ranked interests and travel styles in the actual route; include at least one compatible Event stop whenever a date-matched Event exists, and at least one compatible curated My Maps stop whenever My Maps candidates exist; treat these as source-coverage preferences, not reasons to break time, accessibility or route feasibility; '
			. 'keep quick-search data for event social links and city channels only; use stored official links for contact actions; '
			. 'provide practical selection information through the structured fields; follow instructions.proposal_focus_interests exactly; each proposal must contain at least one stop matching its focus interest whenever a compatible supplied item exists; use the supplied curated My Maps feature data as an active route reference, preserve route_item_ids order where present, and prefer compatible matched_item_ids and candidate_item_ids; inspect configured City Channels and return current high-confidence discoveries in channel_candidates; evaluate current locationless Events in serendipity and explain why they are worth checking; never place an unverified-location Event on the route; '
			. 'ALL generated strings such as title, summary, proposal type, notes and accessibility notes must be written in the selected language. ';
		return $system . ' Return ONLY valid JSON matching this schema: '
			. '{"days":[{"day":1,"proposal_number":1,"proposal_type":"morning|afternoon|evening|flexible",'
			. '"title":"string","summary":"2-4 friendly sentences describing the whole proposal",'
			. '"stops":[{"item_id":123,"time":"HH:MM","note":"practical friendly tip"}],'
			. '"alternatives":[{"item_id":123,"note":"string"}],"serendipity":[{"item_id":123,"note":"string","reason":"string"}],"accessibility_notes":"string",'
			. '"quick_search_hints":["string"]}],"channel_candidates":[{"source_url":"https://configured-source.example/item","name":"string","type":"event|place","summary":"string","start":"YYYY-MM-DD HH:MM:SS or empty","end":"YYYY-MM-DD HH:MM:SS or empty","venue":"string","lat":0,"lng":0,"price_tier":"|free|low|medium|high","categories":["string"],"tags":["string"],"duration":60,"confidence":0.0}],"channel_research":[{"source_url":"https://configured-source.example","checked":true,"current_relevant":false,"reason":"string"}]}';
	}

	private static function ai_plan( array $city, array $form, array $items, $days, $per_day, $lang, array $mymap_context = array() ) {
		$payload = self::ai_payload( $city, $form, $items, $days, $per_day, $lang, $mymap_context );
		$system  = self::system_prompt( $city, $lang );
		$user    = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );

		$cache_key = self::cache_key( $payload, $system );
		$ttl       = (int) PCT_Settings::get( 'ai_cache_ttl', 0 ) * MINUTE_IN_SECONDS;
		if ( $ttl > 0 ) {
			$hit = get_transient( $cache_key );
			if ( is_array( $hit ) ) {
				PCT_AI_Log::record_cache_hit( 'tour', $payload['title'] );
				return $hit;
			}
		}

		// Batching window: identical payloads arriving while one is in flight wait
		// for that answer instead of triggering another provider call.
		$window = (int) PCT_Settings::get( 'ai_batch_window', 0 );
		$lock   = $cache_key . '_lock';
		if ( $window > 0 && get_transient( $lock ) ) {
			$deadline = time() + min( $window, 120 );
			while ( time() < $deadline ) {
				sleep( 1 );
				$hit = get_transient( $cache_key );
				if ( is_array( $hit ) ) {
					PCT_AI_Log::record_cache_hit( 'tour', $payload['title'] );
					return $hit;
				}
				if ( ! get_transient( $lock ) ) {
					break;
				}
			}
			return new WP_Error( 'pct_ai_batched', __( 'An identical request is already being processed; the built-in planner answered this one.', 'personalized-city-tour' ) );
		}
		if ( $window > 0 ) {
			set_transient( $lock, 1, min( $window, 120 ) + 5 );
		}

		$json = PCT_AI::complete_json(
			$system,
			$user,
			array(
				'kind'   => 'tour',
				'title'  => $payload['title'],
				'schema' => PCT_Schema::tour_schema( $days, min( $per_day, (int) PCT_Planner::get()['strategy']['max_stops_per_proposal'] ) ),
				'web_search' => (bool) PCT_Settings::get( 'ai_web_search', 1 ) && 'openai' === (string) PCT_Settings::get( 'ai_provider' ),
				'mock'   => function () use ( $city, $form, $items, $days, $per_day ) {
					$mock = self::rule_based_plan( $city, $form, $items, $days, $per_day );
					$mock['channel_candidates'] = array();
					return $mock;
				},
			)
		);
		if ( $window > 0 ) {
			delete_transient( $lock );
		}
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		if ( $ttl > 0 ) {
			// Cached per payload hash only; the payload never contains contact data
			// unless the admin enabled sharing, and the title (with the name) is excluded.
			set_transient( $cache_key, $json, $ttl );
		}
		return $json;
	}

	/**
	 * Cache key for an AI plan: everything the model sees except the request
	 * title (which carries the traveller's name/date) and the current date.
	 */
	private static function cache_key( array $payload, $system ) {
		unset( $payload['title'], $payload['instructions']['today'] );
		// Signed upload links change on every request; key on the file names only.
		if ( ! empty( $payload['city_database_files']['files'] ) ) {
			$payload['city_database_files']['files'] = array_map(
				function ( $f ) {
					return $f['source'] . ':' . $f['type'] . ':' . $f['updated'];
				},
				$payload['city_database_files']['files']
			);
		}
		$gen = (int) get_option( 'pct_tour_cache_gen', 0 );
		return 'pct_tour_' . PCT_VERSION . '_' . md5( $gen . '|' . wp_json_encode( $payload ) . '|' . $system . '|' . PCT_Settings::get( 'ai_provider' ) . '|' . PCT_Settings::get( 'ai_model' ) );
	}

	/**
	 * Invalidate every cached AI plan (city data or planner rules changed).
	 * Bumps a generation counter that is part of every cache key, so stale
	 * transients simply expire on their own.
	 */
	public static function flush_cache() {
		update_option( 'pct_tour_cache_gen', (int) get_option( 'pct_tour_cache_gen', 0 ) + 1, false );
	}

	/* ---------------------------------------------------------------------
	 * Source coverage / route integrity
	 * ------------------------------------------------------------------ */

	/**
	 * Ensure the final proposal set actually uses the rich sources that the
	 * administrator curated. The AI may choose valid IDs yet still omit every
	 * Event or My Maps point; that is not acceptable for a travel planner.
	 *
	 * We add only candidates already present in the server-side candidate pool.
	 * No place, event, coordinate or link is invented here.
	 */
	private static function enforce_curated_sources( array $result, array $city, array $form, array $items, array $mymap_context = array(), array $channel_items = array() ) {
		$strategy = PCT_Planner::get()['strategy'];
		$events = array_values( array_filter( $items, function ( $item ) {
			return 'event' === ( $item['type'] ?? '' );
		} ) );
		$maps = array_values( array_filter( $items, function ( $item ) {
			return ! empty( $item['mymap_virtual'] ) || ! empty( $item['mymap_matched'] );
		} ) );
		$channels = array_values( array_filter( $channel_items, function ( $item ) { return ! empty( $item['channel_virtual'] ) && ! empty( $item['route_eligible'] ) && ! empty( $item['lat'] ) && ! empty( $item['lng'] ); } ) );
		$route_sequences = array_values( (array) ( $mymap_context['route_sequences'] ?? array() ) );
		$require_event = ! empty( $strategy['require_event'] );
		$require_mymap = ! empty( $strategy['require_mymap'] ) || ! empty( $mymap_context['candidate_items'] );

		if ( ( ! $require_event || empty( $events ) ) && ( ! $require_mymap || empty( $maps ) ) && empty( $channels ) ) {
			return $result;
		}

		$windows = self::proposal_windows( $form );
		$used_events = array();
		$used_maps   = array();
		$used_channels = array();
		$used_channel_sources = array();
		$source_usage = array(
			'events_available' => count( $events ),
			'mymap_points_available' => count( $maps ),
			'mymap_virtual_points_available' => count( (array) ( $mymap_context['candidate_items'] ?? array() ) ),
			'mymap_routes_available' => 0,
			'proposals_with_event' => 0,
			'proposals_with_mymap' => 0,
			'proposals_with_curated_route' => 0,
			'city_channel_candidates_available' => count( $channels ),
			'proposals_with_city_channel' => 0,
			'serendipity_candidates_available' => 0,
			'proposals_with_serendipity' => 0,
		);

		/*
		 * Only sequences with at least two real supplied item IDs are usable as
		 * a route. The plugin, not the AI, owns the final stop order.
		 */
		$usable_routes = array();
		foreach ( $route_sequences as $sequence ) {
			$ids = array();
			foreach ( (array) ( $sequence['item_ids'] ?? array() ) as $rid ) {
				$rid = (int) $rid;
				if ( $rid && self::find_item_by_id( $items, $rid ) ) {
					$ids[] = $rid;
				}
			}
			$ids = array_values( array_unique( $ids ) );
			if ( count( $ids ) >= 2 ) {
				$sequence['item_ids'] = $ids;
				$usable_routes[] = $sequence;
			}
		}
		$source_usage['mymap_routes_available'] = count( $usable_routes );

		foreach ( (array) ( $result['days'] ?? array() ) as &$day ) {
			if ( ! is_array( $day ) ) {
				continue;
			}
			$day_number = max( 1, absint( $day['day'] ?? 1 ) );
			$proposal   = max( 1, min( 4, absint( $day['proposal_number'] ?? 1 ) ) );
			$window     = $windows[ $proposal - 1 ] ?? $windows[0];
			$date       = self::travel_day_date( $form, $day_number );
			$stops      = self::dedupe_stops( array_values( (array) ( $day['stops'] ?? array() ) ) );
			$ids        = array();
			foreach ( $stops as $stop ) {
				$ids[ (int) ( $stop['item_id'] ?? 0 ) ] = true;
			}

			/* First guarantee a compatible date-matched Event when one exists. */
			$event_present = false;
			foreach ( $stops as $stop ) {
				$item = self::find_item_by_id( $items, (int) ( $stop['item_id'] ?? 0 ) );
				if ( $item && 'event' === ( $item['type'] ?? '' ) ) {
					$event_present = true;
					$used_events[ (int) $item['id'] ] = true;
					break;
				}
			}
			if ( $require_event && ! $event_present && $events ) {
				$event = self::pick_event_for_proposal( $events, $date, $window, $stops, $ids, $used_events );
				if ( $event ) {
					$event_stop = array(
						'item_id' => (int) $event['id'],
						'time'    => self::event_time_for_proposal( $event, $window ),
						'note'    => self::stop_note( $event, $form ),
					);
					self::insert_or_replace_source_stop( $stops, $event_stop, $items, 'event', $window );
					$ids[ (int) $event['id'] ] = true;
					$used_events[ (int) $event['id'] ] = true;
					$event_present = true;
				}
			}
			if ( $event_present ) {
				$source_usage['proposals_with_event']++;
			}

			/*
			 * If a curated route exists, use a consecutive segment of that
			 * route as actual tour stops. This is deliberately stronger than
			 * adding one arbitrary My Maps point: the route itself becomes a
			 * source of locations and order.
			 */
			$route = $require_mymap ? self::pick_route_sequence_for_proposal( $usable_routes, $items, $stops, $proposal ) : null;
			if ( $route ) {
				$route_ids = self::route_segment_ids( $route, $items, $proposal, 3 );
				$added_route = false;
				foreach ( $route_ids as $rid ) {
					if ( isset( $ids[ $rid ] ) ) {
						$added_route = true;
						continue;
					}
					$map_item = self::find_item_by_id( $items, $rid );
					if ( ! $map_item ) {
						continue;
					}
					$map_stop = array(
						'item_id' => $rid,
						'time'    => self::nearest_slot_time( $stops, $window ),
						'note'    => self::stop_note( $map_item, $form ),
					);
					self::insert_or_replace_source_stop( $stops, $map_stop, $items, 'mymap', $window );
					$ids[ $rid ] = true;
					$used_maps[ $rid ] = true;
					$added_route = true;
				}
				if ( $added_route ) {
					$source_usage['proposals_with_curated_route']++;
					$source_usage['proposals_with_mymap']++;
					$stops = self::order_curated_route_stops( $stops, $items, $route );
				}
			}

			/* If no route sequence was usable, still force a curated point. */
			if ( $require_mymap && empty( $route ) ) {
				$map_present = false;
				foreach ( $stops as $stop ) {
					$item = self::find_item_by_id( $items, (int) ( $stop['item_id'] ?? 0 ) );
					if ( $item && ( ! empty( $item['mymap_virtual'] ) || ! empty( $item['mymap_matched'] ) ) ) {
						$map_present = true;
						$used_maps[ (int) $item['id'] ] = true;
						break;
					}
				}
				if ( ! $map_present && $maps ) {
					$map_item = self::pick_map_for_proposal( $maps, $stops, $ids, $used_maps );
					if ( $map_item ) {
						$map_stop = array(
							'item_id' => (int) $map_item['id'],
							'time'    => self::nearest_slot_time( $stops, $window ),
							'note'    => self::stop_note( $map_item, $form ),
						);
						self::insert_or_replace_source_stop( $stops, $map_stop, $items, 'mymap', $window );
						$ids[ (int) $map_item['id'] ] = true;
						$used_maps[ (int) $map_item['id'] ] = true;
						$source_usage['proposals_with_mymap']++;
					}
				} elseif ( $map_present ) {
					$source_usage['proposals_with_mymap']++;
				}
			}

			$channel_present = false;
			foreach ( $stops as $stop ) {
				$item = self::find_item_by_id( $items, (int) ( $stop['item_id'] ?? 0 ) );
				if ( $item && ! empty( $item['channel_virtual'] ) ) { $channel_present = true; break; }
			}
			if ( ! $channel_present && $channels ) {
				$channel_item = self::pick_channel_for_proposal( $channels, $stops, $ids, $form, $proposal, $used_channels, $date, $used_channel_sources );
				if ( $channel_item ) {
					$channel_stop = array( 'item_id' => (int) $channel_item['id'], 'time' => self::nearest_slot_time( $stops, $window ), 'note' => self::stop_note( $channel_item, $form ) );
					self::insert_or_replace_source_stop( $stops, $channel_stop, $items, 'channel', $window );
					$ids[(int) $channel_item['id']] = true;
					$used_channels[(int) $channel_item['id']] = true;
					$source_usage['proposals_with_city_channel']++;
				}
			} elseif ( $channel_present ) {
				$source_usage['proposals_with_city_channel']++;
			}

			$day['stops'] = array_values( $stops );
		}
		unset( $day );

		$result['source_usage'] = $source_usage;
		return $result;
	}


	/**
	 * Keep current, date-valid Events without coordinates as Serendipity.
	 * They are intentionally excluded from route planning, but remain useful
	 * as human-checkable discoveries/alternatives in a proposal.
	 */
	private static function attach_serendipity_candidates( array $result, array $items, array $form ) {
		$pool = array();
		foreach ( $items as $item ) {
			if ( 'event' !== ( $item['type'] ?? '' ) ) { continue; }
			if ( ! empty( $item['lat'] ) && ! empty( $item['lng'] ) ) { continue; }
			if ( empty( $item['start'] ) ) { continue; }
			if ( empty( $item['serendipity_eligible'] ) && empty( $item['channel_virtual'] ) ) { continue; }
			$pool[] = $item;
		}
		$available = count( $pool );
		$used = array();
		foreach ( (array) ( $result['days'] ?? array() ) as &$day ) {
			if ( ! is_array( $day ) ) { continue; }
			$day_number = max( 1, absint( $day['day'] ?? 1 ) );
			$proposal = max( 1, min( 4, absint( $day['proposal_number'] ?? 1 ) ) );
			$date = self::travel_day_date( $form, $day_number );
			$stops = (array) ( $day['stops'] ?? array() );
			$ids = array();
			foreach ( $stops as $stop ) { $ids[(int) ( $stop['item_id'] ?? 0 )] = true; }
			$existing = array();
			foreach ( (array) ( $day['serendipity'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) ) { continue; }
				$id = (int) ( $row['item_id'] ?? 0 );
				if ( $id && self::find_item_by_id( $items, $id ) ) { $existing[] = $row; $used[$id] = true; }
			}
			$pick = self::pick_serendipity_event( $pool, $date, $ids, $used, $form, $proposal );
			if ( $pick ) {
				$note = sprintf( __( 'Serendipity discovery: %s. The event is current and date-matched, but its location is not yet verified; confirm the venue before visiting.', 'personalized-city-tour' ), (string) $pick['name'] );
				$existing[] = array( 'item_id' => (int) $pick['id'], 'note' => $note, 'reason' => 'current_date_matched_event_without_verified_location' );
				$used[(int) $pick['id']] = true;
			}
			$day['serendipity'] = array_slice( $existing, 0, 2 );
		}
		unset( $day );
		$result['source_usage']['serendipity_candidates_available'] = $available;
		$result['source_usage']['proposals_with_serendipity'] = 0;
		foreach ( (array) ( $result['days'] ?? array() ) as $day ) {
			if ( ! empty( $day['serendipity'] ) ) { $result['source_usage']['proposals_with_serendipity']++; }
		}
		return $result;
	}

	private static function pick_serendipity_event( array $pool, $date, array $ids, array $used, array $form, $proposal ) {
		$weights = self::interest_weights( $form );
		$best = null; $best_score = -INF;
		foreach ( $pool as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( ! $id || isset( $ids[$id] ) || isset( $used[$id] ) ) { continue; }
			if ( ! self::valid_scheduled_event_for_window( $item, $date, $date ) ) { continue; }
			$start = strtotime( (string) $item['start'] );
			if ( ! $start || ( $date && wp_date( 'Y-m-d', $start, wp_timezone() ) !== $date ) ) { continue; }
			$score = (float) ( $item['score'] ?? 0 );
			foreach ( (array) ( $item['categories'] ?? array() ) as $cat ) {
				if ( isset( $weights[$cat] ) ) { $score += $weights[$cat] * 1.2; }
			}
			if ( ! empty( $item['channel_ai_verified'] ) ) { $score += 4; }
			if ( ! empty( $item['channel_virtual'] ) ) { $score += 3; }
			if ( ! empty( $item['source_url'] ) ) { $score += 1; }
			$score += ( (int) $proposal === 1 ) ? 0.5 : 0;
			if ( $score > $best_score ) { $best_score = $score; $best = $item; }
		}
		return $best;
	}

	private static function pick_channel_for_proposal( array $channels, array $stops, array $ids, array $form, $proposal, array $used_channels = array(), $date = '', array &$used_channel_sources = array() ) {
		$best = null; $best_score = -INF; $weights = self::interest_weights( $form );
		foreach ( $channels as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( ! $id || isset( $ids[$id] ) ) { continue; }
			if ( 'event' === ( $item['type'] ?? '' ) ) {
				if ( ! self::valid_scheduled_event_for_window( $item, $date, $date ) ) { continue; }
				$start = strtotime( $item['start'] );
				if ( $date && wp_date( 'Y-m-d', $start, wp_timezone() ) !== $date ) { continue; }
			}
			$score = (float) ( $item['score'] ?? 0 );
			if ( isset( $used_channels[$id] ) ) { $score -= 4; }
			foreach ( (array) ( $item['categories'] ?? array() ) as $cat ) { if ( isset( $weights[$cat] ) ) { $score += $weights[$cat] * 0.8; } }
			if ( 'event' === ( $item['type'] ?? '' ) ) { $score += 2; }
			if ( ! empty( $item['channel_ai_verified'] ) ) { $score += 3; }
			$host = strtolower( (string) wp_parse_url( (string) ( $item['channel_source_url'] ?? $item['source_url'] ?? '' ), PHP_URL_HOST ) );
			if ( $host && empty( $used_channel_sources[ $host ] ) ) { $score += 12; }
			if ( $host && ! empty( $used_channel_sources[ $host ] ) ) { $score -= 8; }
			$score += ( (int) $proposal % 2 === 0 ) ? 0.2 : 0;
			if ( $stops && isset( $stops[0]['lat'], $stops[0]['lng'], $item['lat'], $item['lng'] ) ) {
				$score -= min( 5, PCT_Data::distance( (float) $stops[0]['lat'], (float) $stops[0]['lng'], (float) $item['lat'], (float) $item['lng'] ) * 0.2 );
			}
			if ( $score > $best_score ) { $best_score = $score; $best = $item; }
		}
		if ( $best ) {
			$host = strtolower( (string) wp_parse_url( (string) ( $best['channel_source_url'] ?? $best['source_url'] ?? '' ), PHP_URL_HOST ) );
			if ( $host ) { $used_channel_sources[ $host ] = true; }
		}
		return $best;
	}

	private static function pick_route_sequence_for_proposal( array $routes, array $items, array $stops, $proposal ) {
		if ( empty( $routes ) ) {
			return null;
		}
		$best = null;
		$best_score = -INF;
		$stop_ids = array();
		foreach ( $stops as $stop ) {
			$stop_ids[ (int) ( $stop['item_id'] ?? 0 ) ] = true;
		}
		foreach ( $routes as $index => $route ) {
			$ids = array_values( array_filter( array_map( 'intval', (array) ( $route['item_ids'] ?? array() ) ), function ( $rid ) use ( $items ) {
				return (bool) self::find_item_by_id( $items, $rid );
			} ) );
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$overlap = 0;
			foreach ( $ids as $rid ) {
				if ( isset( $stop_ids[ $rid ] ) ) {
					$overlap++;
				}
			}
			$score = ( $overlap * 10 ) + ( count( $ids ) * 0.05 );
			/* Spread different route features across the four proposals. */
			$score += ( (int) $proposal - 1 === ( (int) $index % 4 ) ) ? 4 : 0;
			if ( $score > $best_score ) {
				$best_score = $score;
				$best = $route;
				$best['item_ids'] = $ids;
			}
		}
		return $best;
	}

	private static function route_segment_ids( array $route, array $items, $proposal, $count = 3 ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) ( $route['item_ids'] ?? array() ) ), function ( $rid ) use ( $items ) {
			return (bool) self::find_item_by_id( $items, $rid );
		} ) );
		$ids = array_values( array_unique( $ids ) );
		$n = count( $ids );
		if ( $n <= $count ) {
			return $ids;
		}
		$take = max( 2, min( (int) $count, $n ) );
		$max_start = max( 0, $n - $take );
		$start = min( ( max( 1, (int) $proposal ) - 1 ) * 2, $max_start );
		return array_slice( $ids, $start, $take );
	}

	private static function order_curated_route_stops( array $stops, array $items, array $route ) {
		$rank = array();
		foreach ( (array) ( $route['item_ids'] ?? array() ) as $order => $rid ) {
			$rank[ (int) $rid ] = (float) $order;
		}
		$decorated = array();
		foreach ( $stops as $index => $stop ) {
			$id = (int) ( $stop['item_id'] ?? 0 );
			$value = array(
				'stop' => $stop,
				'index' => $index,
				'rank' => isset( $rank[ $id ] ) ? $rank[ $id] : null,
			);
			if ( null === $value['rank'] ) {
				$item = self::find_item_by_id( $items, $id );
				$nearest = null;
				if ( $item && isset( $item['lat'], $item['lng'] ) ) {
					$best_distance = INF;
					foreach ( $rank as $rid => $rnk ) {
						$anchor = self::find_item_by_id( $items, $rid );
						if ( ! $anchor || ! isset( $anchor['lat'], $anchor['lng'] ) ) {
							continue;
						}
						$distance = PCT_Data::distance( (float) $item['lat'], (float) $item['lng'], (float) $anchor['lat'], (float) $anchor['lng'] );
						if ( $distance < $best_distance ) {
							$best_distance = $distance;
							$nearest = $rnk;
						}
					}
				}
				$value['rank'] = null === $nearest ? 999 + $index : $nearest + 0.25;
			}
			$decorated[] = $value;
		}
		usort( $decorated, function ( $a, $b ) {
			if ( $a['rank'] === $b['rank'] ) {
				return $a['index'] <=> $b['index'];
			}
			return $a['rank'] <=> $b['rank'];
		} );
		return array_values( array_map( function ( $row ) { return $row['stop']; }, $decorated ) );
	}

	private static function canonicalize_route_order( array $result, array $items, array $form ) {
		$windows = self::proposal_windows( $form );
		foreach ( (array) ( $result['days'] ?? array() ) as &$day ) {
			if ( ! is_array( $day ) ) {
				continue;
			}
			$stops = self::dedupe_stops( array_values( (array) ( $day['stops'] ?? array() ) ) );
			if ( empty( $stops ) ) {
				$day['stops'] = array();
				continue;
			}
			$route_ids = self::route_order_ids_from_stops( $stops, $items );
			$order_source = 'input_order';
			if ( count( $route_ids ) >= 2 ) {
				$stops = self::order_curated_route_stops( $stops, $items, array( 'item_ids' => $route_ids ) );
				$order_source = 'curated_mymap_route_order';
			} else {
				$geo = self::order_stops_geographically( $stops, $items );
				if ( $geo ) {
					$stops = $geo;
					$order_source = 'geographic_preoptimizer';
				}
			}
			$proposal = max( 1, min( 4, absint( $day['proposal_number'] ?? 1 ) ) );
			$window = $windows[ $proposal - 1 ] ?? $windows[0];
			$day_number = max( 1, absint( $day['day'] ?? 1 ) );
			$day['stops'] = self::sync_stop_times_to_order( $stops, $items, $form, $window, $day_number );
			$day['route_order_source'] = $order_source;
			$day['canonical_stop_ids'] = array_values( array_map( 'intval', wp_list_pluck( $day['stops'], 'item_id' ) ) );
		}
		unset( $day );
		return $result;
	}

	private static function route_order_ids_from_stops( array $stops, array $items ) {
		$rank = array();
		foreach ( $stops as $stop ) {
			$id = (int) ( $stop['item_id'] ?? 0 );
			$item = self::find_item_by_id( $items, $id );
			if ( ! $item || ! isset( $item['mymap_route_order'] ) ) {
				continue;
			}
			$rank[ $id ] = (int) $item['mymap_route_order'];
		}
		if ( count( $rank ) < 2 ) {
			return array();
		}
		asort( $rank, SORT_NUMERIC );
		return array_values( array_map( 'intval', array_keys( $rank ) ) );
	}

	private static function order_stops_geographically( array $stops, array $items ) {
		if ( count( $stops ) < 2 ) {
			return $stops;
		}
		$stop_by_id = array();
		$sortable = array();
		foreach ( $stops as $stop ) {
			$id = (int) ( $stop['item_id'] ?? 0 );
			$item = self::find_item_by_id( $items, $id );
			if ( ! $item || empty( $item['lat'] ) || empty( $item['lng'] ) ) {
				return null;
			}
			$stop_by_id[ $id ] = $stop;
			$sortable[] = $item;
		}
		$ordered_items = self::order_route( $sortable );
		$out = array();
		foreach ( $ordered_items as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( isset( $stop_by_id[ $id ] ) ) {
				$out[] = $stop_by_id[ $id ];
			}
		}
		return count( $out ) === count( $stops ) ? $out : null;
	}

	private static function sync_stop_times_to_order( array $stops, array $items, array $form, array $window, $day_number ) {
		$slots = self::slot_times_for_window( $form, count( $stops ), $window );
		$target_date = self::travel_day_date( $form, $day_number );
		foreach ( $stops as $index => &$stop ) {
			$item = self::find_item_by_id( $items, (int) ( $stop['item_id'] ?? 0 ) );
			$time = '';
			if ( $item && 'event' === ( $item['type'] ?? '' ) && ! empty( $item['start'] ) ) {
				$event_time = self::event_time_for_proposal( $item, $window );
				if ( self::item_time_is_valid( $item, $event_time, $target_date ) ) {
					$time = $event_time;
				}
			}
			if ( '' === $time && $item ) {
				$slot_count = max( 1, count( $slots ) );
				for ( $offset = 0; $offset < $slot_count; $offset++ ) {
					$candidate_time = $slots[ ( $index + $offset ) % $slot_count ] ?? '';
					if ( $candidate_time && self::item_time_is_valid( $item, $candidate_time, $target_date ) ) {
						$time = $candidate_time;
						break;
					}
				}
			}
			$stop['time'] = $time;
			if ( $item && empty( $stop['note'] ) ) {
				$stop['note'] = self::stop_note( $item, $form );
			}
		}
		unset( $stop );
		return $stops;
	}


	private static function dedupe_stops( array $stops ) {
		$out = array();
		$seen = array();
		foreach ( $stops as $stop ) {
			$id = (int) ( $stop['item_id'] ?? 0 );
			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[] = $stop;
		}
		return $out;
	}

	private static function find_item_by_id( array $items, $id ) {
		foreach ( $items as $item ) {
			if ( (int) ( $item['id'] ?? 0 ) === (int) $id ) {
				return $item;
			}
		}
		return null;
	}

	private static function pick_event_for_proposal( array $events, $date, array $window, array $stops, array $ids, array $used ) {
		$best = null;
		$best_score = -INF;
		foreach ( $events as $event ) {
			if ( ! self::valid_scheduled_event_for_window( $event, $date, $date ) ) { continue; }
			$id = (int) ( $event['id'] ?? 0 );
			if ( isset( $ids[ $id ] ) ) {
				continue;
			}
			$start_ts = ! empty( $event['start'] ) ? strtotime( $event['start'] ) : false;
			if ( $start_ts && $date && wp_date( 'Y-m-d', $start_ts, wp_timezone() ) !== $date ) {
				continue;
			}
			$end_ts = ! empty( $event['end'] ) ? strtotime( $event['end'] ) : $start_ts;
			$start_time = $start_ts ? wp_date( 'H:i', $start_ts, wp_timezone() ) : '';
			$end_time   = $end_ts ? wp_date( 'H:i', $end_ts, wp_timezone() ) : '';
			$overlap = false;
			if ( ! $start_ts ) {
				$overlap = true;
			} elseif ( $end_ts && wp_date( 'Y-m-d', $end_ts, wp_timezone() ) === $date ) {
				$start_m = self::hm_minutes( $start_time );
				$end_m   = self::hm_minutes( $end_time );
				$win_a   = self::hm_minutes( $window['start'] );
				$win_b   = self::hm_minutes( $window['end'] );
				$overlap = null !== $start_m && null !== $end_m && null !== $win_a && null !== $win_b && $end_m >= $win_a && $start_m <= $win_b;
			} else {
				$overlap = self::time_in_window( $start_time, $window );
			}
			if ( ! $overlap ) {
				continue;
			}
			$score = (float) ( $event['score'] ?? 0 );
			if ( isset( $used[ $id ] ) ) {
				$score -= 2;
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best = $event;
			}
		}
		return $best;
	}

	private static function pick_map_for_proposal( array $maps, array $stops, array $ids, array $used ) {
		$best = null;
		$best_score = -INF;
		$anchor = null;
		if ( $stops ) {
			$last = end( $stops );
			$anchor = $last;
		}
		foreach ( $maps as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( isset( $ids[ $id ] ) ) {
				continue;
			}
			$score = (float) ( $item['score'] ?? 0 );
			if ( ! empty( $item['mymap_virtual'] ) ) {
				$score += 5;
			}
			if ( isset( $item['mymap_route_order'] ) ) {
				// Curated route points are preferred in their source order. The
				// plugin still lets the AI choose the final subset, but this makes
				// the actual mapped historical route materially influential.
				$score += max( 0, 6 - (int) $item['mymap_route_order'] * 0.5 );
			}
			if ( isset( $used[ $id ] ) ) {
				$score -= 1.5;
			}
			if ( $anchor && isset( $anchor['lat'], $anchor['lng'] ) && isset( $item['lat'], $item['lng'] ) ) {
				$distance = PCT_Data::distance( (float) $anchor['lat'], (float) $anchor['lng'], (float) $item['lat'], (float) $item['lng'] );
				$score -= min( 8, $distance * 0.35 );
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best = $item;
			}
		}
		return $best;
	}

	private static function insert_or_replace_source_stop( array &$stops, array $new_stop, array $items, $source, array $window ) {
		$max = 4;
		if ( count( $stops ) < $max ) {
			$stops[] = $new_stop;
			return;
		}
		// Replace a generic stop before replacing an Event or My Maps stop. This
		// preserves source coverage once it has been established.
		$replace = null;
		foreach ( array_reverse( $stops, true ) as $idx => $stop ) {
			$item = self::find_item_by_id( $items, (int) ( $stop['item_id'] ?? 0 ) );
			$is_event = $item && 'event' === ( $item['type'] ?? '' );
			$is_map   = $item && ( ! empty( $item['mymap_virtual'] ) || ! empty( $item['mymap_matched'] ) );
			if ( ! $is_event && ! $is_map ) {
				$replace = $idx;
				break;
			}
		}
		if ( null === $replace ) {
			$replace = count( $stops ) - 1;
		}
		$stops[ $replace ] = $new_stop;
	}

	private static function time_in_window( $time, array $window ) {
		$m = self::hm_minutes( $time );
		$a = self::hm_minutes( $window['start'] );
		$b = self::hm_minutes( $window['end'] );
		return null !== $m && null !== $a && null !== $b && $m >= $a && $m <= $b;
	}

	private static function event_time_for_proposal( array $event, array $window ) {
		if ( empty( $event['start'] ) ) {
			return $window['start'];
		}
		$ts = strtotime( $event['start'] );
		if ( false === $ts ) { return $window['start']; }
		$time = wp_date( 'H:i', $ts, wp_timezone() );
		if ( self::time_in_window( $time, $window ) ) {
			return $time;
		}
		$start_m = self::hm_minutes( $time );
		$win_a = self::hm_minutes( $window['start'] );
		return null !== $start_m && null !== $win_a && $start_m < $win_a ? $window['start'] : $time;
	}

	private static function nearest_slot_time( array $stops, array $window ) {
		foreach ( $stops as $stop ) {
			if ( ! empty( $stop['time'] ) && self::time_in_window( $stop['time'], $window ) ) {
				return (string) $stop['time'];
			}
		}
		return $window['start'];
	}

	/* ---------------------------------------------------------------------
	 * Normalisation shared by both planners
	 * ------------------------------------------------------------------ */

	private static function finalize( array $result, array $city, array $form, array $items, $lang = 'en' ) {
		$by_id = array();
		foreach ( $items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}
		$mode       = PCT_Maps::travel_mode( $form );
		$accessible = self::needs_accessible( $form );
		$days       = array();

		$source_days = (array) ( $result['days'] ?? array() );
		foreach ( $source_days as $idx => $day ) {
			if ( ! is_array( $day ) ) {
				continue;
			}
			$day_number = max( 1, absint( $day['day'] ?? ( floor( $idx / 2 ) + 1 ) ) );
			$proposal_number = max( 1, min( 4, absint( $day['proposal_number'] ?? ( ( $idx % 2 ) + 1 ) ) ) );
			$stops = array();
			foreach ( (array) ( $day['stops'] ?? array() ) as $stop ) {
				$s = self::normalize_stop( $stop, $by_id, $accessible, $form, self::travel_day_date( $form, $day_number ) );
				if ( $s ) {
					if ( empty( $s['ar_url'] ) && ! empty( $city['ar_url'] ) ) { $s['ar_url'] = esc_url_raw( $city['ar_url'] ); $s['ar_label'] = $city['ar_label']; }
					$stops[] = $s;
				}
			}
			if ( empty( $stops ) ) {
				continue;
			}
			foreach ( $stops as &$role_stop ) {
				$role_stop['stop_role'] = self::stop_role( $role_stop, $form, $proposal_number, $day_number );
			}
			unset( $role_stop );
			$alts = array();
			foreach ( (array) ( $day['alternatives'] ?? array() ) as $alt ) {
				$a = self::normalize_stop( $alt, $by_id, $accessible, $form, self::travel_day_date( $form, $day_number ) );
				if ( $a ) {
					$alts[] = $a;
				}
			}

			$serendipity = array();
			foreach ( (array) ( $day['serendipity'] ?? array() ) as $ser ) {
				$ss = self::normalize_stop( $ser, $by_id, $accessible, $form, self::travel_day_date( $form, $day_number ), true );
				if ( $ss ) {
					$ss['serendipity_reason'] = sanitize_textarea_field( (string) ( $ser['reason'] ?? '' ) );
					$ser_item = self::find_item_by_id( $items, (int) $ss['item_id'] );
					$ss['location_status'] = $ser_item ? sanitize_key( (string) ( $ser_item['location_status'] ?? 'needs_location_enrichment' ) ) : 'needs_location_enrichment';
					$serendipity[] = $ss;
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
			$quick_links = array();
			foreach ( $stops as $stop ) {
				if ( 'event' !== ( $stop['type'] ?? '' ) ) {
					continue;
				}
				foreach ( (array) ( $stop['socials'] ?? array() ) as $social ) {
					if ( empty( $social['url'] ) || ! filter_var( $social['url'], FILTER_VALIDATE_URL ) ) {
						continue;
					}
					$quick_links[] = array( 'label' => (string) ( $social['label'] ?? $stop['name'] ), 'url' => esc_url_raw( $social['url'] ), 'kind' => 'event_social' );
				}
			}
			foreach ( (array) $city['channels'] as $channel ) {
				if ( ! empty( $channel['url'] ) && filter_var( $channel['url'], FILTER_VALIDATE_URL ) ) {
					$quick_links[] = array( 'label' => (string) ( $channel['label'] ?? $channel['url'] ), 'url' => esc_url_raw( $channel['url'] ), 'kind' => 'city_channel' );
				}
			}
			$seen_quick = array();
			$quick_links = array_values( array_filter( $quick_links, function ( $link ) use ( &$seen_quick ) {
				$key = $link['url'];
				if ( isset( $seen_quick[ $key ] ) ) { return false; }
				$seen_quick[ $key ] = true;
				return true;
			} ) );
			$window = self::proposal_windows( $form )[ $proposal_number - 1 ];
			$summary = sanitize_textarea_field( (string) ( $day['summary'] ?? '' ) );
			// AI prose is allowed only when it is consistent with the authoritative
			// stop list. If it names another City Data/KML place that is not actually
			// on this route, replace it with a deterministic summary built from the
			// exact stops that also drive the map and route cards.
			if ( '' === $summary || self::text_mentions_unselected_item( $summary, $stops, $items ) ) {
				$summary = self::proposal_summary( $stops, $form, $city, $window );
			}
			$canonical_ids = array_values( array_map( 'intval', wp_list_pluck( $stops, 'item_id' ) ) );
			$route_order_source = sanitize_key( (string) ( $day['route_order_source'] ?? 'input_order' ) );
			$route_strategy = $route_order_source . '_canonical_order_provider_geometry';

			$days[] = array(
				'day'             => $day_number,
				'proposal_number' => $proposal_number,
				'proposal_type'   => sanitize_key( (string) ( $day['proposal_type'] ?? $window['type'] ) ),
				'title'           => sanitize_text_field( (string) ( $day['title'] ?? sprintf( __( 'Day %d — Option %d', 'personalized-city-tour' ), $day_number, $proposal_number ) ) ),
				'summary'         => $summary,
				'stops'           => $stops,
				'alternatives'    => $alts,
				'serendipity'     => array_slice( $serendipity, 0, 2 ),
				'accessibility_notes' => $acc_notes,
				'quick_search_hints'  => array_slice( $hints, 0, 8 ),
				'quick_search_links'  => array_slice( $quick_links, 0, 12 ),
				'selection_info'  => self::selection_info( $stops, $form, $window, $proposal_number, $day_number ),
				'route_strategy'  => $route_strategy,
				'route_order_source' => $route_order_source,
				'canonical_stop_ids' => $canonical_ids,
				'map'             => array(
					'mode'       => $mode,
					'available_modes' => PCT_Maps::travel_modes( $form ),
					'route_provider' => PCT_Settings::get( 'routing_provider', 'valhalla' ),
					'canonical_stop_ids' => $canonical_ids,
					'google_url' => PCT_Maps::google_directions_url( $stops, $mode ),
					'osm_url'    => PCT_Maps::osm_directions_url( $stops, $mode ),
				),
			);
		}

		// Guarantee the public contract: two proposals for every requested day
		// whenever candidate data exists. If AI returned fewer, the deterministic
		// planner supplies the missing options.
		$expected = max( 4, self::days_count( $form ) * 4 );
		$keys = array();
		foreach ( $days as $existing ) {
			$keys[ (int) $existing['day'] . ':' . (int) $existing['proposal_number'] ] = true;
		}
		if ( count( $keys ) < $expected ) {
			$fallback = self::rule_based_plan( $city, $form, $items, self::days_count( $form ), self::stops_per_day( $form ) );
			foreach ( (array) $fallback['days'] as $fd ) {
				$key = (int) $fd['day'] . ':' . (int) $fd['proposal_number'];
				if ( ! isset( $keys[ $key ] ) ) {
					$one = self::finalize_single_day( $fd, $city, $form, $items, $by_id, $accessible, $mode );
					if ( ! empty( $one['stops'] ) ) {
						$days[] = $one;
						$keys[ $key ] = true;
					}
				}
			}
		}
		usort( $days, function ( $a, $b ) {
			return ( (int) $a['day'] * 10 + (int) $a['proposal_number'] ) <=> ( (int) $b['day'] * 10 + (int) $b['proposal_number'] );
		} );

		$name = trim( (string) ( $form['name'] ?? '' ) );
		list( $date_from, $date_to ) = self::date_window( $form );
		if ( $date_from ) {
			$from_ts = strtotime( $date_from . ' 00:00:00' );
			$to_ts   = $date_to ? strtotime( $date_to . ' 00:00:00' ) : $from_ts;
			$date = PCT_I18n::format_date( $from_ts, $lang );
			if ( $to_ts && $to_ts !== $from_ts ) {
				$date .= ' — ' . PCT_I18n::format_date( $to_ts, $lang );
			}
		} else {
			$date = __( 'Flexible dates', 'personalized-city-tour' );
		}
		$title = sprintf( __( 'Personalized half-day tour — %1$s — date: %2$s — %3$s', 'personalized-city-tour' ), $name ? $name : __( 'Traveller', 'personalized-city-tour' ), $date, $city['name'] );

		$overall_summary = sanitize_textarea_field( (string) ( $result['summary'] ?? '' ) );
		if ( '' === $overall_summary && $days ) {
			$overall_summary = implode( ' ', array_slice( array_values( array_filter( wp_list_pluck( $days, 'summary' ) ) ), 0, 2 ) );
		}
		$out = array(
			'title'   => $title,
			'summary' => $overall_summary,
			'city'    => array(
				'id'         => $city['id'],
				'name'       => $city['name'],
				'lat'        => $city['lat'],
				'lng'        => $city['lng'],
				'zoom'       => $city['zoom'],
				'channels'   => $city['channels'],
				'gmaps_url'  => $city['gmaps_url'],
				'ar_url'     => $city['ar_url'],
				'ar_label'   => $city['ar_label'],
				'mymaps'     => PCT_Settings::get( 'show_mymaps_public', 0 ) ? $city['mymaps'] : array(),
				'search_url' => PCT_Maps::search_url(),
			),
			'days'    => $days,
		);
		if ( ! empty( $result['source_usage'] ) ) {
			$out['source_usage'] = $result['source_usage'];
		}
		return $out;
	}

	private static function finalize_single_day( array $day, array $city, array $form, array $items, array $by_id, $accessible, $mode ) {
		$day_number = max( 1, absint( $day['day'] ?? 1 ) );
		$canon = self::canonicalize_route_order( array( 'days' => array( $day ) ), $items, $form );
		$day = $canon['days'][0] ?? $day;
		$stops = array();
		foreach ( (array) ( $day['stops'] ?? array() ) as $stop ) {
			$s = self::normalize_stop( $stop, $by_id, $accessible, $form, self::travel_day_date( $form, $day_number ) );
			if ( $s ) {
				if ( empty( $s['ar_url'] ) && ! empty( $city['ar_url'] ) ) { $s['ar_url'] = esc_url_raw( $city['ar_url'] ); $s['ar_label'] = $city['ar_label']; }
				$stops[] = $s;
			}
		}
		$proposal_number = max( 1, min( 4, absint( $day['proposal_number'] ?? 1 ) ) );
		foreach ( $stops as &$role_stop ) {
			$role_stop['stop_role'] = self::stop_role( $role_stop, $form, $proposal_number, $day_number );
		}
		unset( $role_stop );
		$window = self::proposal_windows( $form )[ $proposal_number - 1 ];
		$quick_links = array();
		foreach ( $stops as $stop ) {
			if ( 'event' !== ( $stop['type'] ?? '' ) ) { continue; }
			foreach ( (array) ( $stop['socials'] ?? array() ) as $social ) {
				if ( ! empty( $social['url'] ) && filter_var( $social['url'], FILTER_VALIDATE_URL ) ) {
					$quick_links[] = array( 'label' => (string) ( $social['label'] ?? $stop['name'] ), 'url' => esc_url_raw( $social['url'] ), 'kind' => 'event_social' );
				}
			}
		}
		foreach ( (array) $city['channels'] as $channel ) {
			if ( ! empty( $channel['url'] ) && filter_var( $channel['url'], FILTER_VALIDATE_URL ) ) {
				$quick_links[] = array( 'label' => (string) ( $channel['label'] ?? $channel['url'] ), 'url' => esc_url_raw( $channel['url'] ), 'kind' => 'city_channel' );
			}
		}
		$serendipity = array();
		$ser_pool = array();
		$stop_ids = array(); foreach ( $stops as $st ) { $stop_ids[(int) ( $st['item_id'] ?? 0 )] = true; }
		foreach ( $items as $candidate ) {
			if ( 'event' === ( $candidate['type'] ?? '' ) && empty( $candidate['lat'] ) && empty( $candidate['lng'] ) && ! empty( $candidate['start'] ) ) { $ser_pool[] = $candidate; }
		}
		$ser_pick = self::pick_serendipity_event( $ser_pool, self::travel_day_date( $form, $day_number ), $stop_ids, array(), $form, $proposal_number );
		if ( $ser_pick ) {
			$ss = self::normalize_stop( array( 'item_id' => (int) $ser_pick['id'], 'note' => '' ), $by_id, $accessible, $form, self::travel_day_date( $form, $day_number ), true );
			if ( $ss ) {
				$ss['serendipity_reason'] = 'current_date_matched_event_without_verified_location';
				$ss['location_status'] = (string) ( $ser_pick['location_status'] ?? 'needs_location_enrichment' );
				$serendipity[] = $ss;
			}
		}
		$single_summary = sanitize_textarea_field( (string) ( $day['summary'] ?? '' ) );
		if ( '' === $single_summary || self::text_mentions_unselected_item( $single_summary, $stops, $items ) ) {
			$single_summary = self::proposal_summary( $stops, $form, $city, $window );
		}
		$canonical_ids = array_values( array_map( 'intval', wp_list_pluck( $stops, 'item_id' ) ) );
		$route_order_source = sanitize_key( (string) ( $day['route_order_source'] ?? 'input_order' ) );
		return array(
			'day' => absint( $day['day'] ?? 1 ),
			'proposal_number' => $proposal_number,
			'proposal_type' => $window['type'],
			'title' => sanitize_text_field( (string) ( $day['title'] ?? '' ) ),
			'summary' => $single_summary,
			'stops' => $stops,
			'alternatives' => array(),
			'serendipity' => array_slice( $serendipity, 0, 2 ),
			'accessibility_notes' => self::accessibility_notes( $stops, $form ),
			'quick_search_hints' => self::search_hints( $stops, $city, $form ),
			'quick_search_links' => array_slice( $quick_links, 0, 12 ),
			'selection_info' => self::selection_info( $stops, $form, $window, $proposal_number, $day_number ),
			'route_strategy' => $route_order_source . '_canonical_order_provider_geometry',
			'route_order_source' => $route_order_source,
			'canonical_stop_ids' => $canonical_ids,
			'map' => array(
				'mode' => $mode,
				'available_modes' => PCT_Maps::travel_modes( $form ),
				'route_provider' => PCT_Settings::get( 'routing_provider', 'valhalla' ),
				'canonical_stop_ids' => $canonical_ids,
				'google_url' => PCT_Maps::google_directions_url( $stops, $mode ),
				'osm_url' => PCT_Maps::osm_directions_url( $stops, $mode ),
			),
		);
	}


	/**
	 * Classify each real route stop by its role in the experience. This keeps
	 * the final architecture explicit: Core focus, Supporting interest,
	 * Experience, Mobility or Comfort. Serendipity is deliberately kept out of
	 * this method because it is not a route stop.
	 */
	private static function stop_role( array $item, array $form, $proposal_number = 1, $day_number = 1 ) {
		$cats = array_map( 'sanitize_key', (array) ( $item['categories'] ?? array() ) );
		$focuses = self::proposal_focus_interests( $form );
		$interests = array_values( array_filter( array_map( 'sanitize_key', (array) ( $form['interests'] ?? array() ) ) ) );
		$focus_index = $interests ? ( ( max( 1, (int) $day_number ) - 1 ) * 4 + max( 0, min( 3, (int) $proposal_number - 1 ) ) ) % count( $interests ) : 0;
		$focus = sanitize_key( (string) ( $interests[ $focus_index ] ?? ( $focuses[ max(1, min(4, (int)$proposal_number) ) ] ?? '' ) ) );
		$name = strtolower( (string) ( $item['name'] ?? '' ) . ' ' . (string) ( $item['summary'] ?? '' ) );
		if ( $focus && self::item_matches_interest( $item, $focus ) ) { return 'core'; }
		foreach ( array( 'metro', 'station', 'transport', 'transit', 'parking', 'bike', 'bicycle' ) as $term ) {
			if ( in_array( $term, $cats, true ) || false !== strpos( $name, $term ) ) { return 'mobility'; }
		}
		foreach ( array( 'cafe', 'restaurant', 'food', 'coffee', 'park', 'garden', 'seating', 'market' ) as $term ) {
			if ( in_array( $term, $cats, true ) || false !== strpos( $name, $term ) ) { return 'comfort'; }
		}
		$ranked = array_values( array_filter( array_map( 'sanitize_key', (array) ( $form['interests'] ?? array() ) ) ) );
		foreach ( $ranked as $interest ) {
			if ( $focus !== $interest && self::item_matches_interest( $item, $interest ) ) { return 'supporting'; }
		}
		return 'experience';
	}


	private static function proposal_focus_interests( array $form ) {
		$interests = array_values( array_filter( array_map( 'sanitize_key', (array) ( $form['interests'] ?? array() ) ) ) );
		if ( empty( $interests ) || empty( PCT_Planner::get()['strategy']['interest_rotation'] ) ) {
			return array();
		}
		$out = array();
		for ( $i = 0; $i < 4; $i++ ) { $out[ $i + 1 ] = $interests[ $i % count( $interests ) ]; }
		return $out;
	}

	private static function item_matches_interest( array $item, $interest ) {
		$interest = sanitize_key( $interest );
		return in_array( $interest, array_map( 'sanitize_key', (array) ( $item['categories'] ?? array() ) ), true )
			|| in_array( strtolower( $interest ), array_map( 'strtolower', array_map( 'sanitize_text_field', (array) ( $item['tags'] ?? array() ) ) ), true );
	}

	private static function apply_rotating_interest_focus( array $result, array $city, array $form, array $items ) {
		$focuses = self::proposal_focus_interests( $form );
		if ( empty( $focuses ) ) { return $result; }
		$by_id = array(); foreach ( $items as $item ) { $by_id[ (int) $item['id'] ] = $item; }
		$windows = self::proposal_windows( $form );
		$interests = array_values( array_filter( array_map( 'sanitize_key', (array) ( $form['interests'] ?? array() ) ) ) );
		if ( empty( $interests ) ) { return $result; }
		foreach ( (array) ( $result['days'] ?? array() ) as &$day ) {
			$proposal = max( 1, min( 4, absint( $day['proposal_number'] ?? 1 ) ) );
			$global_focus_index = ( max( 1, absint( $day['day'] ?? 1 ) ) - 1 ) * 4 + ( $proposal - 1 );
			$focus = $interests[ $global_focus_index % count( $interests ) ] ?? ( $focuses[ $proposal ] ?? '' );
			if ( ! $focus ) { continue; }
			$stop_ids = array(); $has_focus = false;
			foreach ( (array) ( $day['stops'] ?? array() ) as $stop ) {
				$id = (int) ( $stop['item_id'] ?? 0 ); $stop_ids[ $id ] = true;
				if ( isset( $by_id[ $id ] ) && self::item_matches_interest( $by_id[ $id ], $focus ) ) { $has_focus = true; }
			}
			if ( $has_focus ) { continue; }
			$candidates = array();
			$date = self::travel_day_date( $form, max( 1, absint( $day['day'] ?? 1 ) ) );
			$window = $windows[ $proposal - 1 ] ?? $windows[0];
			foreach ( $items as $item ) {
				$id = (int) $item['id'];
				if ( isset( $stop_ids[ $id ] ) || ! self::item_matches_interest( $item, $focus ) ) { continue; }
				if ( 'event' === ( $item['type'] ?? '' ) && ! self::event_matches_date_window( $item, $date, $window ) ) { continue; }
				$item['focus_score'] = (float) ( $item['score'] ?? 0 ) + ( ! empty( $item['mymap_matched'] ) ? 3 : 0 );
				$candidates[] = $item;
			}
			if ( empty( $candidates ) ) { continue; }
			usort( $candidates, function ( $a, $b ) { return ( $b['focus_score'] ?? 0 ) <=> ( $a['focus_score'] ?? 0 ); } );
			$pick = $candidates[0];
			$new_stop = array( 'item_id' => (int) $pick['id'], 'time' => self::nearest_slot_time( (array) ( $day['stops'] ?? array() ), $window ), 'note' => self::stop_note( $pick, $form ) );
			$stops = (array) ( $day['stops'] ?? array() );
			if ( count( $stops ) < 4 ) { $stops[] = $new_stop; } else {
				$replace = count( $stops ) - 1;
				foreach ( array_reverse( $stops, true ) as $idx => $old ) {
					$old_item = $by_id[ (int) ( $old['item_id'] ?? 0 ) ] ?? null;
					if ( ! $old_item || ( 'event' !== ( $old_item['type'] ?? '' ) && empty( $old_item['mymap_matched'] ) && empty( $old_item['mymap_virtual'] ) ) ) { $replace = $idx; break; }
				}
				$stops[ $replace ] = $new_stop;
			}
			$day['stops'] = $stops;
		}
		unset( $day );
		return $result;
	}


	private static function valid_scheduled_event_for_window( array $event, $from = '', $to = '' ) {
		if ( 'event' !== ( $event['type'] ?? '' ) ) { return true; }
		if ( empty( $event['start'] ) ) { return false; }
		$start = strtotime( $event['start'] );
		if ( false === $start ) { return false; }
		$end = ! empty( $event['end'] ) ? strtotime( $event['end'] ) : $start;
		if ( false === $end ) { $end = $start; }
		$from_ts = $from ? strtotime( $from . ' 00:00:00' ) : current_time( 'timestamp' );
		$to_ts   = $to ? strtotime( $to . ' 23:59:59' ) : ( current_time( 'timestamp' ) + 60 * DAY_IN_SECONDS );
		if ( $end < $from_ts || $start > $to_ts ) { return false; }
		// Never allow an event that has already finished relative to today,
		// even if a stale source reports it without a usable requested window.
		if ( $end < current_time( 'timestamp' ) ) { return false; }
		return true;
	}

	private static function event_matches_date_window( array $event, $date, array $window ) {
		if ( empty( $event['start'] ) ) { return false; }
		$start = strtotime( $event['start'] ); $end = ! empty( $event['end'] ) ? strtotime( $event['end'] ) : $start;
		if ( false === $start ) { return true; }
		if ( $date && wp_date( 'Y-m-d', $start, wp_timezone() ) !== $date ) { return false; }
		$sm = self::hm_minutes( wp_date( 'H:i', $start, wp_timezone() ) ); $em = $end ? self::hm_minutes( wp_date( 'H:i', $end, wp_timezone() ) ) : $sm;
		$wa = self::hm_minutes( $window['start'] ); $wb = self::hm_minutes( $window['end'] );
		return null === $sm || null === $wa || null === $wb ? true : ( $em >= $wa && $sm <= $wb );
	}

	private static function text_mentions_unselected_item( $text, array $stops, array $items ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $text ) {
			return false;
		}
		$selected = array();
		foreach ( $stops as $stop ) {
			$name = trim( (string) ( $stop['name'] ?? '' ) );
			if ( '' !== $name ) { $selected[ mb_strtolower( $name ) ] = true; }
		}
		foreach ( $items as $item ) {
			$name = trim( (string) ( $item['name'] ?? '' ) );
			if ( mb_strlen( $name ) < 4 || isset( $selected[ mb_strtolower( $name ) ] ) ) {
				continue;
			}
			if ( false !== mb_stripos( $text, $name ) ) {
				return true;
			}
		}
		return false;
	}

	private static function actual_stop_window( array $stops, array $fallback ) {
		$start = null;
		$end   = null;
		foreach ( $stops as $stop ) {
			$time = self::hm_minutes( $stop['time'] ?? '' );
			if ( null === $time ) { continue; }
			$duration = max( 15, (int) ( $stop['duration'] ?? 45 ) );
			$start = null === $start ? $time : min( $start, $time );
			$end   = null === $end ? $time + $duration : max( $end, $time + $duration );
		}
		if ( null === $start || null === $end ) {
			return $fallback['start'] . '–' . $fallback['end'];
		}
		$fmt = function ( $minutes ) {
			$minutes = max( 0, min( 1439, (int) $minutes ) );
			return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
		};
		return $fmt( $start ) . '–' . $fmt( $end );
	}

	private static function selection_info( array $stops, array $form, array $window, $proposal_number = 1, $day_number = 1 ) {
		$duration = 0;
		$prices = array();
		$accessible = true;
		foreach ( $stops as $s ) {
			$duration += max( 0, (int) $s['duration'] );
			if ( $s['price_tier'] ) {
				$prices[] = $s['price_tier'];
			}
			if ( ! $s['accessible'] ) {
				$accessible = false;
			}
		}
		$tier_rank = array( 'free' => 0, 'low' => 1, 'medium' => 2, 'high' => 3 );
		$max = '';
		foreach ( $prices as $p ) {
			if ( ! $max || ( $tier_rank[ $p ] ?? 0 ) > ( $tier_rank[ $max ] ?? 0 ) ) {
				$max = $p;
			}
		}
		$fields = PCT_Form_Builder::field_map( PCT_Form_Builder::get_schema() );
		$style_label = isset( $fields['travel_style'] ) ? PCT_Form_Builder::display_value( $fields['travel_style'], $form['travel_style'] ?? array() ) : '';
		$interests = (array) ( $form['interests'] ?? array() );
		$priority_label = '';
		if ( $interests && isset( $fields['interests'] ) ) {
			$focus_index = ! empty( PCT_Planner::get()['strategy']['interest_rotation'] ) ? ( ( max( 1, (int) $day_number ) - 1 ) * 4 + max( 0, ( (int) $proposal_number - 1 ) ) ) % count( $interests ) : 0;
			$priority_label = PCT_Form_Builder::display_value( $fields['interests'], $interests[ $focus_index ] );
		}
		$preferred_label = isset( $fields['preferred_times'] ) ? PCT_Form_Builder::display_value( $fields['preferred_times'], $form['preferred_times'] ?? array() ) : '';
		$actual_window = self::actual_stop_window( $stops, $window );
		return array(
			'time_window' => $actual_window,
			'stop_count' => count( $stops ),
			'estimated_visit_minutes' => $duration,
			'max_price_tier' => $max,
			'accessibility' => $accessible ? 'accessible' : 'check_stop_access',
			'pace' => sanitize_key( (string) ( $form['pace'] ?? 'moderate' ) ),
			'travel_styles' => $style_label,
			'priority_interest' => $priority_label,
			'preferred_times' => $preferred_label,
		);
	}

	private static function normalize_stop( $stop, array $by_id, $accessible, array $form = array(), $target_date = '', $allow_locationless = false ) {
		if ( ! is_array( $stop ) ) {
			return null;
		}
		$id   = (int) ( $stop['item_id'] ?? $stop['id'] ?? 0 );
		$item = isset( $by_id[ $id ] ) ? $by_id[ $id ] : PCT_Data::item( $id );
		if ( ! $item ) {
			return null;
		}
		if ( ! $allow_locationless && ( empty( $item['lat'] ) || empty( $item['lng'] ) ) ) {
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
		if ( $time && ! self::item_time_is_valid( $item, $time, $target_date ) ) {
			// A scheduled time that conflicts with known event/opening-hour data
			// is rejected rather than silently shown as a plausible appointment.
			return null;
		}
		$links = ! empty( $item['links'] ) ? $item['links'] : PCT_Data::best_links( $item );
		$mymap_public = (bool) PCT_Settings::get( 'show_mymaps_public', 0 );
		return array(
			'item_id'       => $item['id'],
			'type'          => $item['type'],
			'name'          => $item['name'],
			'summary'       => $item['summary'],
			'categories'    => array_values( (array) ( $item['categories'] ?? array() ) ),
			'address'       => $item['address'],
			'lat'           => $item['lat'],
			'lng'           => $item['lng'],
			'link'          => $item['link'],
			'gmaps_url'     => PCT_Maps::item_google_url( $item ),
			'image'         => $item['image'] ?? '',
			'links'         => $links,
			'mymap_url'     => $mymap_public && ! empty( $item['mymap_url'] ) ? esc_url_raw( $item['mymap_url'] ) : '',
			'mymap_label'   => $mymap_public && ! empty( $item['mymap_label'] ) ? sanitize_text_field( $item['mymap_label'] ) : '',
			'socials'       => $item['socials'],
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
			'ar_url'        => ! empty( $item['ar_url'] ) ? esc_url_raw( $item['ar_url'] ) : '',
			'ar_label'      => sanitize_text_field( (string) ( $item['ar_label'] ?? __( 'Augmented reality', 'personalized-city-tour' ) ) ),
			'channel_source_url' => ! empty( $item['channel_source_url'] ) ? esc_url_raw( $item['channel_source_url'] ) : '',
			'channel_virtual' => ! empty( $item['channel_virtual'] ),
			'acc_warning'   => $accessible && ( ! empty( $item['acc_unknown'] ) || ! in_array( 'wheelchair', $item['accessibility'], true ) ),
		);
	}

	private static function item_time_is_valid( array $item, $time, $target_date = '' ) {
		$minutes = self::hm_minutes( $time );
		if ( null === $minutes ) {
			return false;
		}
		if ( 'event' === ( $item['type'] ?? '' ) ) {
			$start = self::datetime_minutes( $item['start'] ?? '' );
			$end   = self::datetime_minutes( $item['end'] ?? '' );
			if ( null !== $start && null !== $end ) {
				if ( $target_date && $start['date'] !== $target_date ) {
					return false;
				}
				$event_start = $start['minutes'];
				$event_end   = $end['minutes'];
				if ( $start['date'] === $end['date'] ) {
					return $minutes >= $event_start && $minutes <= $event_end;
				}
			}
		}
		$hours = trim( (string) ( $item['hours'] ?? '' ) );
		if ( '' === $hours ) {
			return true;
		}
		// Accept common formats such as 09:00-17:00 and multiple intervals.
		if ( preg_match_all( '/(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})/', $hours, $m ) ) {
			foreach ( $m[1] as $i => $from ) {
				$a = self::hm_minutes( $from );
				$b = self::hm_minutes( $m[2][ $i ] );
				if ( null !== $a && null !== $b && $minutes >= $a && $minutes <= $b ) {
					return true;
				}
			}
			return false;
		}
		return true; // Free-form hours are shown to the visitor but not guessed.
	}

	private static function hm_minutes( $value ) {
		if ( ! preg_match( '/^(\d{1,2}):([0-5]\d)$/', trim( (string) $value ), $m ) ) {
			return null;
		}
		return ((int) $m[1]) * 60 + (int) $m[2];
	}

	private static function datetime_minutes( $value ) {
		$value = trim( (string) $value );
		if ( ! $value ) {
			return null;
		}
		$ts = strtotime( $value );
		if ( false === $ts ) {
			return null;
		}
		return array(
			'date' => wp_date( 'Y-m-d', $ts, wp_timezone() ),
			'minutes' => ((int) wp_date( 'G', $ts, wp_timezone() )) * 60 + (int) wp_date( 'i', $ts, wp_timezone() ),
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
