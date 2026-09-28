<?php
/**
 * Minimal JSON-schema validator (draft-07 subset) used to strictly check AI output.
 *
 * Supported keywords: type, properties, required, additionalProperties, items,
 * minItems, maxItems, enum, minimum, maximum, minLength, maxLength, pattern.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_Schema {

	/**
	 * @return array List of error strings (empty when valid).
	 */
	public static function validate( $data, array $schema, $path = '$' ) {
		$errors = array();
		$type   = $schema['type'] ?? null;

		if ( $type ) {
			$types = (array) $type;
			$ok    = false;
			foreach ( $types as $t ) {
				if ( self::is_type( $data, $t ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				$errors[] = sprintf( '%s: expected %s, got %s', $path, implode( '|', $types ), self::type_of( $data ) );
				return $errors;
			}
		}

		if ( isset( $schema['enum'] ) && ! in_array( $data, (array) $schema['enum'], true ) ) {
			$errors[] = sprintf( '%s: value not in enum', $path );
		}

		if ( is_string( $data ) ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $data ) : strlen( $data );
			if ( isset( $schema['minLength'] ) && $len < $schema['minLength'] ) {
				$errors[] = sprintf( '%s: shorter than %d characters', $path, $schema['minLength'] );
			}
			if ( isset( $schema['maxLength'] ) && $len > $schema['maxLength'] ) {
				$errors[] = sprintf( '%s: longer than %d characters', $path, $schema['maxLength'] );
			}
			if ( isset( $schema['pattern'] ) && ! preg_match( '~' . $schema['pattern'] . '~u', $data ) ) {
				$errors[] = sprintf( '%s: does not match pattern %s', $path, $schema['pattern'] );
			}
		}

		if ( is_int( $data ) || is_float( $data ) ) {
			if ( isset( $schema['minimum'] ) && $data < $schema['minimum'] ) {
				$errors[] = sprintf( '%s: below minimum %s', $path, $schema['minimum'] );
			}
			if ( isset( $schema['maximum'] ) && $data > $schema['maximum'] ) {
				$errors[] = sprintf( '%s: above maximum %s', $path, $schema['maximum'] );
			}
		}

		if ( self::is_type( $data, 'array' ) ) {
			$n = count( $data );
			if ( isset( $schema['minItems'] ) && $n < $schema['minItems'] ) {
				$errors[] = sprintf( '%s: fewer than %d items', $path, $schema['minItems'] );
			}
			if ( isset( $schema['maxItems'] ) && $n > $schema['maxItems'] ) {
				$errors[] = sprintf( '%s: more than %d items', $path, $schema['maxItems'] );
			}
			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				foreach ( $data as $i => $item ) {
					$errors = array_merge( $errors, self::validate( $item, $schema['items'], $path . '[' . $i . ']' ) );
				}
			}
		}

		if ( self::is_type( $data, 'object' ) ) {
			$props = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
			foreach ( (array) ( $schema['required'] ?? array() ) as $req ) {
				if ( ! array_key_exists( $req, $data ) ) {
					$errors[] = sprintf( '%s: missing required property "%s"', $path, $req );
				}
			}
			foreach ( $data as $key => $value ) {
				if ( isset( $props[ $key ] ) ) {
					$errors = array_merge( $errors, self::validate( $value, $props[ $key ], $path . '.' . $key ) );
				} elseif ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
					$errors[] = sprintf( '%s: unexpected property "%s"', $path, $key );
				}
			}
		}

		return $errors;
	}

	private static function is_type( $data, $type ) {
		switch ( $type ) {
			case 'object':
				return is_array( $data ) && ( empty( $data ) || array_keys( $data ) !== range( 0, count( $data ) - 1 ) );
			case 'array':
				return is_array( $data ) && ( empty( $data ) || array_keys( $data ) === range( 0, count( $data ) - 1 ) );
			case 'string':
				return is_string( $data );
			case 'integer':
				return is_int( $data ) || ( is_string( $data ) && preg_match( '/^-?\d+$/', $data ) );
			case 'number':
				return is_int( $data ) || is_float( $data ) || ( is_string( $data ) && is_numeric( $data ) );
			case 'boolean':
				return is_bool( $data );
			case 'null':
				return null === $data;
		}
		return false;
	}

	private static function type_of( $data ) {
		if ( null === $data ) {
			return 'null';
		}
		if ( is_array( $data ) ) {
			return self::is_type( $data, 'array' ) ? 'array' : 'object';
		}
		return gettype( $data );
	}

	/**
	 * Schema for the personalized tour JSON expected from the AI.
	 */
	public static function tour_schema( $days = 1, $stops_per_day = 3 ) {
		$stop = array(
			'type'                 => 'object',
			'required'             => array( 'item_id' ),
			'additionalProperties' => true,
			'properties'           => array(
				'item_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'time'    => array( 'type' => 'string', 'pattern' => '^([01]?\d|2[0-3]):[0-5]\d$' ),
				'note'    => array( 'type' => 'string', 'maxLength' => 600 ),
			),
		);
		return array(
			'type'                 => 'object',
			'required'             => array( 'days' ),
			'additionalProperties' => true,
			'properties'           => array(
				'title'   => array( 'type' => 'string', 'maxLength' => 200 ),
				'summary' => array( 'type' => 'string', 'maxLength' => 2000 ),
				'days'    => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => max( 1, (int) $days ),
					'items'    => array(
						'type'                 => 'object',
						'required'             => array( 'day', 'stops' ),
						'additionalProperties' => true,
						'properties'           => array(
							'day'                 => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 14 ),
							'title'               => array( 'type' => 'string', 'maxLength' => 200 ),
							'summary'             => array( 'type' => 'string', 'maxLength' => 2000 ),
							'stops'               => array(
								'type'     => 'array',
								'minItems' => 1,
								'maxItems' => max( 3, (int) $stops_per_day + 2 ),
								'items'    => $stop,
							),
							'alternatives'        => array( 'type' => 'array', 'maxItems' => 6, 'items' => $stop ),
							'accessibility_notes' => array( 'type' => 'string', 'maxLength' => 2000 ),
							'quick_search_hints'  => array( 'type' => 'array', 'maxItems' => 8, 'items' => array( 'type' => 'string', 'maxLength' => 120 ) ),
						),
					),
				),
			),
		);
	}

	/**
	 * Schema for analytics answers.
	 */
	public static function analysis_schema() {
		return array(
			'type'                 => 'object',
			'required'             => array( 'summary' ),
			'additionalProperties' => true,
			'properties'           => array(
				'summary'  => array( 'type' => 'string', 'maxLength' => 6000 ),
				'insights' => array( 'type' => 'array', 'maxItems' => 20, 'items' => array( 'type' => 'string', 'maxLength' => 600 ) ),
				'charts'   => array(
					'type'     => 'array',
					'maxItems' => 8,
					'items'    => array(
						'type'       => 'object',
						'required'   => array( 'title', 'labels', 'values' ),
						'properties' => array(
							'title'  => array( 'type' => 'string', 'maxLength' => 200 ),
							'type'   => array( 'type' => 'string', 'enum' => array( 'bar', 'line', 'pie' ) ),
							'labels' => array( 'type' => 'array', 'maxItems' => 50, 'items' => array( 'type' => 'string', 'maxLength' => 80 ) ),
							'values' => array( 'type' => 'array', 'maxItems' => 50, 'items' => array( 'type' => 'number' ) ),
						),
					),
				),
				'tables'   => array(
					'type'     => 'array',
					'maxItems' => 8,
					'items'    => array(
						'type'       => 'object',
						'required'   => array( 'title', 'columns', 'rows' ),
						'properties' => array(
							'title'   => array( 'type' => 'string', 'maxLength' => 200 ),
							'columns' => array( 'type' => 'array', 'maxItems' => 12, 'items' => array( 'type' => 'string', 'maxLength' => 80 ) ),
							'rows'    => array( 'type' => 'array', 'maxItems' => 200, 'items' => array( 'type' => 'array' ) ),
						),
					),
				),
			),
		);
	}
}
