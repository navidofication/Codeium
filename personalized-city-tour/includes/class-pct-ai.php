<?php
/**
 * AI provider abstraction. All providers are asked for a single JSON document.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_AI {

	public static function providers() {
		return array(
			'none'      => __( 'Disabled — use built-in rule based planner', 'personalized-city-tour' ),
			'openai'    => __( 'OpenAI (ChatGPT API)', 'personalized-city-tour' ),
			'azure'     => __( 'Microsoft Azure OpenAI / Copilot', 'personalized-city-tour' ),
			'anthropic' => __( 'Anthropic Claude', 'personalized-city-tour' ),
			'custom'    => __( 'Custom OpenAI-compatible endpoint (Groq, Mistral, Ollama…)', 'personalized-city-tour' ),
		);
	}

	public static function enabled() {
		$provider = PCT_Settings::get( 'ai_provider' );
		return 'none' !== $provider && '' !== PCT_Settings::ai_key();
	}

	/**
	 * Ask the configured provider for a JSON object.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User prompt.
	 * @return array|WP_Error Decoded JSON.
	 */
	public static function complete_json( $system, $user ) {
		$provider = PCT_Settings::get( 'ai_provider' );
		$key      = PCT_Settings::ai_key();
		if ( 'none' === $provider || '' === $key ) {
			return new WP_Error( 'pct_ai_disabled', __( 'AI provider is not configured.', 'personalized-city-tour' ) );
		}

		$model       = PCT_Settings::get( 'ai_model' );
		$temperature = floatval( PCT_Settings::get( 'ai_temperature' ) );
		$endpoint    = PCT_Settings::get( 'ai_endpoint' );

		switch ( $provider ) {
			case 'anthropic':
				$url     = $endpoint ? $endpoint : 'https://api.anthropic.com/v1/messages';
				$headers = array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
				);
				$body    = array(
					'model'       => $model ? $model : 'claude-3-5-haiku-latest',
					'max_tokens'  => 4000,
					'temperature' => $temperature,
					'system'      => $system . "\n\nRespond with a single JSON object and nothing else.",
					'messages'    => array(
						array(
							'role'    => 'user',
							'content' => $user,
						),
					),
				);
				break;

			case 'azure':
				// Endpoint example: https://my-resource.openai.azure.com/openai/deployments/my-deployment
				if ( ! $endpoint ) {
					return new WP_Error( 'pct_ai_config', __( 'Azure OpenAI requires the deployment endpoint URL.', 'personalized-city-tour' ) );
				}
				$url = untrailingslashit( $endpoint );
				if ( false === strpos( $url, '/chat/completions' ) ) {
					$url .= '/chat/completions';
				}
				if ( false === strpos( $url, 'api-version=' ) ) {
					$url = add_query_arg( 'api-version', '2024-08-01-preview', $url );
				}
				$headers = array(
					'Content-Type' => 'application/json',
					'api-key'      => $key,
				);
				$body    = self::openai_body( $model, $system, $user, $temperature );
				break;

			case 'custom':
				if ( ! $endpoint ) {
					return new WP_Error( 'pct_ai_config', __( 'The custom provider requires an endpoint URL.', 'personalized-city-tour' ) );
				}
				$url = untrailingslashit( $endpoint );
				if ( false === strpos( $url, '/chat/completions' ) ) {
					$url .= '/chat/completions';
				}
				$headers = array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				);
				$body    = self::openai_body( $model, $system, $user, $temperature );
				break;

			case 'openai':
			default:
				$url     = $endpoint ? $endpoint : 'https://api.openai.com/v1/chat/completions';
				$headers = array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				);
				$body    = self::openai_body( $model ? $model : 'gpt-4o-mini', $system, $user, $temperature );
				break;
		}

		$body = apply_filters( 'pct_ai_request_body', $body, $provider );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 90,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : wp_remote_retrieve_response_message( $response );
			/* translators: 1: HTTP status, 2: provider error message */
			return new WP_Error( 'pct_ai_http', sprintf( __( 'AI request failed (%1$s): %2$s', 'personalized-city-tour' ), $code, $msg ) );
		}

		$text = '';
		if ( 'anthropic' === $provider ) {
			foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
				if ( isset( $block['text'] ) ) {
					$text .= $block['text'];
				}
			}
		} else {
			$text = $data['choices'][0]['message']['content'] ?? '';
		}

		$json = self::extract_json( $text );
		if ( null === $json ) {
			return new WP_Error( 'pct_ai_parse', __( 'The AI response could not be parsed as JSON.', 'personalized-city-tour' ) );
		}
		return $json;
	}

	private static function openai_body( $model, $system, $user, $temperature ) {
		return array(
			'model'           => $model,
			'temperature'     => $temperature,
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
		);
	}

	/**
	 * Tolerate code fences or leading prose around the JSON object.
	 */
	public static function extract_json( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * Quick connectivity test used from the settings page.
	 */
	public static function test() {
		$result = self::complete_json(
			'You are a connectivity test. Reply with JSON only.',
			'Return {"ok": true, "message": "hello"}'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return ! empty( $result['ok'] ) ? true : new WP_Error( 'pct_ai_test', __( 'Unexpected response from provider.', 'personalized-city-tour' ) );
	}
}
