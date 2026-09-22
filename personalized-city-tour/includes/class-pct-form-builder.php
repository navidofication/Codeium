<?php
/**
 * Travel preference form: schema storage, sanitizing and submission validation.
 *
 * Schema shape:
 * {
 *   sections: [ { id, title, description, condition?, fields: [
 *     { id, type, label, required, placeholder, help, options: [{value,label}], min, max, step,
 *       condition?: { field, operator, value } }
 *   ] } ]
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Form_Builder {

	const OPTION = 'pct_form_schema';

	public static function init() {}

	public static function field_types() {
		return array(
			'text'     => __( 'Text', 'personalized-city-tour' ),
			'email'    => __( 'Email', 'personalized-city-tour' ),
			'tel'      => __( 'Phone', 'personalized-city-tour' ),
			'number'   => __( 'Number', 'personalized-city-tour' ),
			'textarea' => __( 'Long text', 'personalized-city-tour' ),
			'select'   => __( 'Dropdown', 'personalized-city-tour' ),
			'radio'    => __( 'Single choice', 'personalized-city-tour' ),
			'checkbox' => __( 'Multiple choice', 'personalized-city-tour' ),
			'priority' => __( 'Ranked choice (prioritize)', 'personalized-city-tour' ),
			'range'    => __( 'Slider / score', 'personalized-city-tour' ),
			'date'     => __( 'Date picker', 'personalized-city-tour' ),
			'toggle'   => __( 'Yes / No', 'personalized-city-tour' ),
		);
	}

	public static function operators() {
		return array(
			'equals'       => __( 'equals', 'personalized-city-tour' ),
			'not_equals'   => __( 'does not equal', 'personalized-city-tour' ),
			'contains'     => __( 'contains (multi-choice)', 'personalized-city-tour' ),
			'not_contains' => __( 'does not contain', 'personalized-city-tour' ),
			'not_empty'    => __( 'is not empty', 'personalized-city-tour' ),
			'gt'           => __( 'greater than', 'personalized-city-tour' ),
			'lt'           => __( 'less than', 'personalized-city-tour' ),
		);
	}

	public static function get_schema() {
		$schema = get_option( self::OPTION );
		if ( ! is_array( $schema ) || empty( $schema['sections'] ) ) {
			$schema = self::sanitize_schema( self::default_schema() );
		}
		return apply_filters( 'pct_form_schema', $schema );
	}

	public static function save_schema( $schema ) {
		$clean = self::sanitize_schema( $schema );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		update_option( self::OPTION, $clean );
		return $clean;
	}

	public static function reset_schema() {
		$clean = self::sanitize_schema( self::default_schema() );
		update_option( self::OPTION, $clean );
		return $clean;
	}

	private static function opt( $value, $label ) {
		return array(
			'value' => $value,
			'label' => $label,
		);
	}

	private static function cond( $field, $operator, $value = '' ) {
		return array(
			'field'    => $field,
			'operator' => $operator,
			'value'    => $value,
		);
	}

	public static function default_schema() {
		$f = array();

		/* Section 1: basic info */
		$basic = array(
			array( 'id' => 'name', 'type' => 'text', 'label' => __( 'Full name', 'personalized-city-tour' ), 'required' => true ),
			array( 'id' => 'email', 'type' => 'email', 'label' => __( 'Email', 'personalized-city-tour' ), 'required' => true ),
			array( 'id' => 'phone', 'type' => 'tel', 'label' => __( 'Phone (optional)', 'personalized-city-tour' ) ),
			array(
				'id'       => 'group_type',
				'type'     => 'radio',
				'label'    => __( 'Who is travelling?', 'personalized-city-tour' ),
				'required' => true,
				'options'  => array(
					self::opt( 'solo', __( 'Just me', 'personalized-city-tour' ) ),
					self::opt( 'couple', __( 'Couple', 'personalized-city-tour' ) ),
					self::opt( 'family', __( 'Family with children', 'personalized-city-tour' ) ),
					self::opt( 'friends', __( 'Group of friends', 'personalized-city-tour' ) ),
					self::opt( 'business', __( 'Business trip', 'personalized-city-tour' ) ),
				),
			),
			array( 'id' => 'companions', 'type' => 'number', 'label' => __( 'Number of companions', 'personalized-city-tour' ), 'min' => 0, 'max' => 50 ),
			array(
				'id'        => 'children_count',
				'type'      => 'number',
				'label'     => __( 'How many children?', 'personalized-city-tour' ),
				'min'       => 1,
				'max'       => 20,
				'condition' => self::cond( 'group_type', 'equals', 'family' ),
			),
			array(
				'id'        => 'children_ages',
				'type'      => 'checkbox',
				'label'     => __( 'Children age groups', 'personalized-city-tour' ),
				'options'   => array(
					self::opt( 'toddler', __( '0-3 (stroller)', 'personalized-city-tour' ) ),
					self::opt( 'kids', __( '4-9', 'personalized-city-tour' ) ),
					self::opt( 'tweens', __( '10-13', 'personalized-city-tour' ) ),
					self::opt( 'teens', __( '14-17', 'personalized-city-tour' ) ),
				),
				'condition' => self::cond( 'group_type', 'equals', 'family' ),
			),
			array( 'id' => 'nights', 'type' => 'number', 'label' => __( 'Number of nights in the city', 'personalized-city-tour' ), 'required' => true, 'min' => 1, 'max' => 14 ),
			array(
				'id'      => 'age_group',
				'type'    => 'select',
				'label'   => __( 'Your age group', 'personalized-city-tour' ),
				'options' => array(
					self::opt( '18-25', '18-25' ),
					self::opt( '26-35', '26-35' ),
					self::opt( '36-50', '36-50' ),
					self::opt( '51-65', '51-65' ),
					self::opt( '65+', '65+' ),
				),
			),
			array(
				'id'       => 'dates_mode',
				'type'     => 'radio',
				'label'    => __( 'Preferred dates', 'personalized-city-tour' ),
				'required' => true,
				'options'  => array(
					self::opt( 'flexible', __( 'Flexible / not decided', 'personalized-city-tour' ) ),
					self::opt( 'this_week', __( 'Within the next 7 days', 'personalized-city-tour' ) ),
					self::opt( 'custom', __( 'Specific dates', 'personalized-city-tour' ) ),
				),
			),
			array( 'id' => 'date_start', 'type' => 'date', 'label' => __( 'Arrival date', 'personalized-city-tour' ), 'required' => true, 'condition' => self::cond( 'dates_mode', 'equals', 'custom' ) ),
			array( 'id' => 'date_end', 'type' => 'date', 'label' => __( 'Departure date', 'personalized-city-tour' ), 'condition' => self::cond( 'dates_mode', 'equals', 'custom' ) ),
		);

		/* Section 2: travel style and pace */
		$style = array(
			array(
				'id'       => 'travel_style',
				'type'     => 'checkbox',
				'label'    => __( 'Travel style', 'personalized-city-tour' ),
				'required' => true,
				'options'  => array(
					self::opt( 'classic', __( 'Classic highlights', 'personalized-city-tour' ) ),
					self::opt( 'offbeat', __( 'Off the beaten path', 'personalized-city-tour' ) ),
					self::opt( 'relaxed', __( 'Slow & relaxed', 'personalized-city-tour' ) ),
					self::opt( 'luxury', __( 'Comfort & luxury', 'personalized-city-tour' ) ),
					self::opt( 'budget', __( 'Backpacker / budget', 'personalized-city-tour' ) ),
					self::opt( 'photography', __( 'Photography spots', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'       => 'pace',
				'type'     => 'radio',
				'label'    => __( 'Preferred pace', 'personalized-city-tour' ),
				'required' => true,
				'options'  => array(
					self::opt( 'slow', __( 'Slow — 1-2 stops per day', 'personalized-city-tour' ) ),
					self::opt( 'moderate', __( 'Moderate — 3 stops per day', 'personalized-city-tour' ) ),
					self::opt( 'fast', __( 'Packed — see as much as possible', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'      => 'guide_preference',
				'type'    => 'toggle',
				'label'   => __( 'Would you like a local guide to join you?', 'personalized-city-tour' ),
			),
			array(
				'id'        => 'guide_language',
				'type'      => 'text',
				'label'     => __( 'Preferred guide language', 'personalized-city-tour' ),
				'placeholder' => __( 'e.g. English, Spanish', 'personalized-city-tour' ),
				'condition' => self::cond( 'guide_preference', 'equals', 'yes' ),
			),
		);

		/* Section 3: interests */
		$interests = array(
			array(
				'id'       => 'interests',
				'type'     => 'priority',
				'label'    => __( 'Select your interests and order them by priority', 'personalized-city-tour' ),
				'help'     => __( 'Tick what you like, then use the arrows to move the most important ones to the top.', 'personalized-city-tour' ),
				'required' => true,
				'options'  => array(
					self::opt( 'historical', __( 'Historical sites', 'personalized-city-tour' ) ),
					self::opt( 'cultural', __( 'Culture & traditions', 'personalized-city-tour' ) ),
					self::opt( 'museums', __( 'Museums', 'personalized-city-tour' ) ),
					self::opt( 'artistic', __( 'Art & galleries', 'personalized-city-tour' ) ),
					self::opt( 'architecture', __( 'Architecture', 'personalized-city-tour' ) ),
					self::opt( 'food', __( 'Food & local cuisine', 'personalized-city-tour' ) ),
					self::opt( 'shopping', __( 'Shopping', 'personalized-city-tour' ) ),
					self::opt( 'markets', __( 'Local markets', 'personalized-city-tour' ) ),
					self::opt( 'nature', __( 'Nature & parks', 'personalized-city-tour' ) ),
					self::opt( 'nightlife', __( 'Nightlife', 'personalized-city-tour' ) ),
					self::opt( 'social', __( 'Social life & meeting locals', 'personalized-city-tour' ) ),
					self::opt( 'religious', __( 'Religious sites', 'personalized-city-tour' ) ),
					self::opt( 'family', __( 'Family activities', 'personalized-city-tour' ) ),
					self::opt( 'viewpoints', __( 'Viewpoints & photography', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'    => 'interests_notes',
				'type'  => 'textarea',
				'label' => __( 'Anything specific you want to see or avoid?', 'personalized-city-tour' ),
			),
		);

		/* Section 4: habits & expectations */
		$habits = array(
			array(
				'id'      => 'preferred_times',
				'type'    => 'checkbox',
				'label'   => __( 'Preferred times of day', 'personalized-city-tour' ),
				'options' => array(
					self::opt( 'morning', __( 'Morning', 'personalized-city-tour' ) ),
					self::opt( 'afternoon', __( 'Afternoon', 'personalized-city-tour' ) ),
					self::opt( 'evening', __( 'Evening', 'personalized-city-tour' ) ),
					self::opt( 'night', __( 'Night', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'      => 'solo_or_group',
				'type'    => 'radio',
				'label'   => __( 'Do you prefer exploring alone or in a group?', 'personalized-city-tour' ),
				'options' => array(
					self::opt( 'solo', __( 'On my own / with my companions', 'personalized-city-tour' ) ),
					self::opt( 'small_group', __( 'Small group tours', 'personalized-city-tour' ) ),
					self::opt( 'any', __( 'No preference', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'      => 'mobility_modes',
				'type'    => 'checkbox',
				'label'   => __( 'How do you like to move around?', 'personalized-city-tour' ),
				'options' => array(
					self::opt( 'walking', __( 'Walking', 'personalized-city-tour' ) ),
					self::opt( 'public_transport', __( 'Public transport', 'personalized-city-tour' ) ),
					self::opt( 'bike', __( 'Bike / scooter', 'personalized-city-tour' ) ),
					self::opt( 'taxi', __( 'Taxi / ride-hailing', 'personalized-city-tour' ) ),
					self::opt( 'car', __( 'Own / rental car', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'      => 'activity_level',
				'type'    => 'radio',
				'label'   => __( 'Activity level', 'personalized-city-tour' ),
				'options' => array(
					self::opt( 'low', __( 'Low — short distances, plenty of breaks', 'personalized-city-tour' ) ),
					self::opt( 'medium', __( 'Medium — a few kilometres of walking is fine', 'personalized-city-tour' ) ),
					self::opt( 'high', __( 'High — happy to walk and climb all day', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'    => 'local_experience',
				'type'  => 'range',
				'label' => __( 'How local do you want the experience to be?', 'personalized-city-tour' ),
				'help'  => __( '1 = tourist classics, 10 = live like a local', 'personalized-city-tour' ),
				'min'   => 1,
				'max'   => 10,
				'step'  => 1,
			),
		);

		/* Section 5: constraints & budget */
		$constraints = array(
			array(
				'id'      => 'mobility_limitations',
				'type'    => 'checkbox',
				'label'   => __( 'Mobility limitations', 'personalized-city-tour' ),
				'options' => array(
					self::opt( 'none', __( 'None', 'personalized-city-tour' ) ),
					self::opt( 'wheelchair', __( 'Wheelchair user', 'personalized-city-tour' ) ),
					self::opt( 'stroller', __( 'Travelling with a stroller', 'personalized-city-tour' ) ),
					self::opt( 'limited_walking', __( 'Limited walking distance', 'personalized-city-tour' ) ),
					self::opt( 'no_stairs', __( 'Avoid stairs / steep paths', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'        => 'accessibility_notes',
				'type'      => 'textarea',
				'label'     => __( 'Accessibility details we should know', 'personalized-city-tour' ),
				'condition' => self::cond( 'mobility_limitations', 'contains', 'wheelchair' ),
			),
			array(
				'id'      => 'transport_limits',
				'type'    => 'checkbox',
				'label'   => __( 'Transport limits', 'personalized-city-tour' ),
				'options' => array(
					self::opt( 'no_metro', __( 'Avoid metro / underground', 'personalized-city-tour' ) ),
					self::opt( 'no_long_walks', __( 'No walks over 20 minutes', 'personalized-city-tour' ) ),
					self::opt( 'no_night_transport', __( 'No travelling after dark', 'personalized-city-tour' ) ),
					self::opt( 'car_free', __( 'Car-free only', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'       => 'budget_tier',
				'type'     => 'radio',
				'label'    => __( 'Budget per person per day (excluding hotel)', 'personalized-city-tour' ),
				'required' => true,
				'options'  => array(
					self::opt( 'low', __( 'Low — free & cheap options', 'personalized-city-tour' ) ),
					self::opt( 'medium', __( 'Medium', 'personalized-city-tour' ) ),
					self::opt( 'high', __( 'High — premium experiences welcome', 'personalized-city-tour' ) ),
				),
			),
		);

		/* Conditional dietary section (shown when food is an interest) */
		$dietary = array(
			array(
				'id'      => 'dietary',
				'type'    => 'checkbox',
				'label'   => __( 'Dietary restrictions', 'personalized-city-tour' ),
				'options' => array(
					self::opt( 'none', __( 'None', 'personalized-city-tour' ) ),
					self::opt( 'vegetarian', __( 'Vegetarian', 'personalized-city-tour' ) ),
					self::opt( 'vegan', __( 'Vegan', 'personalized-city-tour' ) ),
					self::opt( 'halal', __( 'Halal', 'personalized-city-tour' ) ),
					self::opt( 'kosher', __( 'Kosher', 'personalized-city-tour' ) ),
					self::opt( 'gluten_free', __( 'Gluten-free', 'personalized-city-tour' ) ),
					self::opt( 'nut_allergy', __( 'Nut allergy', 'personalized-city-tour' ) ),
				),
			),
			array(
				'id'    => 'food_notes',
				'type'  => 'text',
				'label' => __( 'Dishes or food experiences you would love to try', 'personalized-city-tour' ),
			),
		);

		return array(
			'version'  => 1,
			'sections' => array(
				array(
					'id'          => 'basic',
					'title'       => __( 'Basic information', 'personalized-city-tour' ),
					'description' => __( 'Tell us who is travelling and when.', 'personalized-city-tour' ),
					'fields'      => self::normalize_fields( $basic ),
				),
				array(
					'id'          => 'style',
					'title'       => __( 'Travel style & pace', 'personalized-city-tour' ),
					'description' => '',
					'fields'      => self::normalize_fields( $style ),
				),
				array(
					'id'          => 'interests',
					'title'       => __( 'Interests', 'personalized-city-tour' ),
					'description' => '',
					'fields'      => self::normalize_fields( $interests ),
				),
				array(
					'id'          => 'habits',
					'title'       => __( 'Habits & expectations', 'personalized-city-tour' ),
					'description' => '',
					'fields'      => self::normalize_fields( $habits ),
				),
				array(
					'id'          => 'constraints',
					'title'       => __( 'Constraints & budget', 'personalized-city-tour' ),
					'description' => '',
					'fields'      => self::normalize_fields( $constraints ),
				),
				array(
					'id'          => 'dietary',
					'title'       => __( 'Food & dietary needs', 'personalized-city-tour' ),
					'description' => __( 'Because food is one of your interests.', 'personalized-city-tour' ),
					'condition'   => self::cond( 'interests', 'contains', 'food' ),
					'fields'      => self::normalize_fields( $dietary ),
				),
			),
		);
	}

	private static function normalize_fields( array $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			$out[] = wp_parse_args(
				$field,
				array(
					'type'        => 'text',
					'label'       => '',
					'required'    => false,
					'placeholder' => '',
					'help'        => '',
					'options'     => array(),
					'min'         => '',
					'max'         => '',
					'step'        => '',
					'condition'   => null,
				)
			);
		}
		return $out;
	}

	/**
	 * Sanitize a schema coming from the admin editor.
	 *
	 * @return array|WP_Error
	 */
	public static function sanitize_schema( $schema ) {
		if ( ! is_array( $schema ) || empty( $schema['sections'] ) || ! is_array( $schema['sections'] ) ) {
			return new WP_Error( 'pct_invalid_schema', __( 'The form must contain at least one section.', 'personalized-city-tour' ) );
		}
		$types    = array_keys( self::field_types() );
		$ops      = array_keys( self::operators() );
		$seen_ids = array();
		$sections = array();

		foreach ( $schema['sections'] as $s_index => $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$sid   = sanitize_key( $section['id'] ?? '' );
			$sid   = $sid ? $sid : 'section_' . ( $s_index + 1 );
			$clean = array(
				'id'          => $sid,
				'title'       => sanitize_text_field( $section['title'] ?? '' ),
				'description' => sanitize_textarea_field( $section['description'] ?? '' ),
				'condition'   => self::sanitize_condition( $section['condition'] ?? null, $ops ),
				'fields'      => array(),
			);
			foreach ( (array) ( $section['fields'] ?? array() ) as $f_index => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$fid = sanitize_key( $field['id'] ?? '' );
				if ( ! $fid ) {
					$fid = $sid . '_field_' . ( $f_index + 1 );
				}
				if ( isset( $seen_ids[ $fid ] ) ) {
					/* translators: %s: field id */
					return new WP_Error( 'pct_duplicate_field', sprintf( __( 'Duplicate field key: %s', 'personalized-city-tour' ), $fid ) );
				}
				$seen_ids[ $fid ] = true;
				$type             = in_array( $field['type'] ?? '', $types, true ) ? $field['type'] : 'text';
				$options          = array();
				foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
					if ( is_string( $opt ) ) {
						$opt = array(
							'value' => $opt,
							'label' => $opt,
						);
					}
					if ( ! is_array( $opt ) ) {
						continue;
					}
					$val = sanitize_text_field( $opt['value'] ?? '' );
					$lab = sanitize_text_field( $opt['label'] ?? $val );
					if ( '' === $val && '' === $lab ) {
						continue;
					}
					$options[] = array(
						'value' => '' === $val ? sanitize_title( $lab ) : $val,
						'label' => '' === $lab ? $val : $lab,
					);
				}
				$clean['fields'][] = array(
					'id'          => $fid,
					'type'        => $type,
					'label'       => sanitize_text_field( $field['label'] ?? '' ),
					'required'    => ! empty( $field['required'] ),
					'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
					'help'        => sanitize_text_field( $field['help'] ?? '' ),
					'options'     => $options,
					'min'         => '' === ( $field['min'] ?? '' ) ? '' : floatval( $field['min'] ),
					'max'         => '' === ( $field['max'] ?? '' ) ? '' : floatval( $field['max'] ),
					'step'        => '' === ( $field['step'] ?? '' ) ? '' : floatval( $field['step'] ),
					'condition'   => self::sanitize_condition( $field['condition'] ?? null, $ops ),
				);
			}
			$sections[] = $clean;
		}

		return array(
			'version'  => 1,
			'sections' => $sections,
		);
	}

	private static function sanitize_condition( $cond, $ops ) {
		if ( ! is_array( $cond ) || empty( $cond['field'] ) ) {
			return null;
		}
		return array(
			'field'    => sanitize_key( $cond['field'] ),
			'operator' => in_array( $cond['operator'] ?? '', $ops, true ) ? $cond['operator'] : 'equals',
			'value'    => sanitize_text_field( $cond['value'] ?? '' ),
		);
	}

	/**
	 * Evaluate a condition against submitted data.
	 */
	public static function condition_met( $cond, array $data ) {
		if ( empty( $cond ) || empty( $cond['field'] ) ) {
			return true;
		}
		$value = isset( $data[ $cond['field'] ] ) ? $data[ $cond['field'] ] : null;
		$want  = (string) ( $cond['value'] ?? '' );
		switch ( $cond['operator'] ) {
			case 'not_empty':
				return is_array( $value ) ? ! empty( $value ) : ( null !== $value && '' !== (string) $value );
			case 'contains':
				return is_array( $value ) ? in_array( $want, array_map( 'strval', $value ), true ) : (string) $value === $want;
			case 'not_contains':
				return is_array( $value ) ? ! in_array( $want, array_map( 'strval', $value ), true ) : (string) $value !== $want;
			case 'not_equals':
				return is_array( $value ) ? ! in_array( $want, array_map( 'strval', $value ), true ) : (string) $value !== $want;
			case 'gt':
				return is_numeric( $value ) && floatval( $value ) > floatval( $want );
			case 'lt':
				return is_numeric( $value ) && floatval( $value ) < floatval( $want );
			case 'equals':
			default:
				return is_array( $value ) ? in_array( $want, array_map( 'strval', $value ), true ) : (string) $value === $want;
		}
	}

	/**
	 * Validate and sanitize a submission against the schema.
	 *
	 * @return array|WP_Error Clean data keyed by field id.
	 */
	public static function validate_submission( array $schema, array $raw ) {
		$clean  = array();
		$errors = array();
		$fields = array();

		// First pass: sanitize by type so conditions can be evaluated on clean data.
		foreach ( $schema['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$fields[ $field['id'] ] = array( $field, $section );
				$clean[ $field['id'] ]  = self::sanitize_value( $field, isset( $raw[ $field['id'] ] ) ? $raw[ $field['id'] ] : null );
			}
		}

		foreach ( $fields as $fid => $pair ) {
			list( $field, $section ) = $pair;
			$visible = self::condition_met( $section['condition'] ?? null, $clean ) && self::condition_met( $field['condition'] ?? null, $clean );
			if ( ! $visible ) {
				unset( $clean[ $fid ] );
				continue;
			}
			$value    = $clean[ $fid ];
			$is_empty = is_array( $value ) ? empty( $value ) : ( null === $value || '' === $value );
			if ( ! empty( $field['required'] ) && $is_empty ) {
				/* translators: %s: field label */
				$errors[ $fid ] = sprintf( __( '%s is required.', 'personalized-city-tour' ), $field['label'] ? $field['label'] : $fid );
				continue;
			}
			if ( 'email' === $field['type'] && ! $is_empty && ! is_email( $value ) ) {
				$errors[ $fid ] = __( 'Please enter a valid email address.', 'personalized-city-tour' );
			}
			if ( in_array( $field['type'], array( 'select', 'radio', 'checkbox', 'priority' ), true ) && ! $is_empty && ! empty( $field['options'] ) ) {
				$allowed = wp_list_pluck( $field['options'], 'value' );
				$vals    = is_array( $value ) ? $value : array( $value );
				foreach ( $vals as $v ) {
					if ( ! in_array( (string) $v, array_map( 'strval', $allowed ), true ) ) {
						$errors[ $fid ] = __( 'Invalid choice.', 'personalized-city-tour' );
						break;
					}
				}
			}
		}

		if ( $errors ) {
			return new WP_Error( 'pct_validation', __( 'Please check the highlighted fields.', 'personalized-city-tour' ), array( 'fields' => $errors ) );
		}
		return $clean;
	}

	private static function sanitize_value( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'checkbox':
			case 'priority':
				$value = is_array( $value ) ? $value : ( null === $value || '' === $value ? array() : array( $value ) );
				return array_values( array_unique( array_map( 'sanitize_text_field', array_map( 'strval', $value ) ) ) );
			case 'number':
			case 'range':
				if ( null === $value || '' === $value || is_array( $value ) ) {
					return '';
				}
				$num = floatval( $value );
				if ( '' !== $field['min'] && $num < floatval( $field['min'] ) ) {
					$num = floatval( $field['min'] );
				}
				if ( '' !== $field['max'] && $num > floatval( $field['max'] ) ) {
					$num = floatval( $field['max'] );
				}
				return ( floor( $num ) === $num ) ? (int) $num : $num;
			case 'toggle':
				return in_array( $value, array( 'yes', '1', 1, true, 'true', 'on' ), true ) ? 'yes' : ( null === $value || '' === $value ? '' : 'no' );
			case 'date':
				$value = is_array( $value ) ? '' : sanitize_text_field( (string) $value );
				return PCT_I18n::parse_date( $value );
			case 'email':
				return is_array( $value ) ? '' : sanitize_email( (string) $value );
			case 'textarea':
				return is_array( $value ) ? '' : sanitize_textarea_field( (string) $value );
			default:
				return is_array( $value ) ? '' : sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Flat map of field id => field definition (labels/options) for display.
	 */
	public static function field_map( array $schema ) {
		$map = array();
		foreach ( $schema['sections'] as $section ) {
			foreach ( $section['fields'] as $field ) {
				$map[ $field['id'] ] = $field;
			}
		}
		return $map;
	}

	/**
	 * Human readable label for a stored value.
	 */
	public static function display_value( array $field, $value ) {
		if ( is_array( $value ) ) {
			$labels = array();
			foreach ( $value as $v ) {
				$labels[] = self::display_value( $field, $v );
			}
			return implode( ', ', $labels );
		}
		foreach ( (array) $field['options'] as $opt ) {
			if ( (string) $opt['value'] === (string) $value ) {
				return $opt['label'];
			}
		}
		return (string) $value;
	}
}
