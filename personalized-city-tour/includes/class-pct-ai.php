<?php
/**
 * AI provider abstraction.
 *
 * Providers: Devin.ai (sessions API), OpenAI, Azure OpenAI, Anthropic and any
 * OpenAI-compatible endpoint. Every call goes through complete_json(), which
 * applies the sandbox (mock) mode, rate limiting, audit logging, strict JSON
 * schema validation and a single retry with an adjusted prompt.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PCT_AI {

	const RATE_TRANSIENT = 'pct_ai_rate_';

	public static function providers() {
		return array(
			'none'      => __( 'Disabled — use built-in rule based planner', 'personalized-city-tour' ),
			'devin'     => __( 'Devin.ai (Cognition) — sessions API', 'personalized-city-tour' ),
			'openai'    => __( 'OpenAI (ChatGPT API)', 'personalized-city-tour' ),
			'azure'     => __( 'Microsoft Azure OpenAI / Copilot', 'personalized-city-tour' ),
			'anthropic' => __( 'Anthropic Claude', 'personalized-city-tour' ),
			'custom'    => __( 'Custom OpenAI-compatible endpoint (Groq, Mistral, Ollama, OpenRouter…)', 'personalized-city-tour' ),
		);
	}

	/**
	 * Built-in model / deployment suggestions per provider. Admins can extend
	 * the list through the provider → model → deployment mapping in settings.
	 */
	public static function default_models() {
		return array(
			'devin'     => array(
				array( 'model' => 'devin', 'deployment' => '', 'label' => 'Devin (default)' ),
				array( 'model' => 'devin-fast', 'deployment' => '', 'label' => 'Devin Fast' ),
			),
			'openai'    => array(
				array( 'model' => 'gpt-5.6-luna', 'deployment' => '', 'label' => 'GPT-5.6 Luna (cost-optimized)' ),
				array( 'model' => 'gpt-5.6-terra', 'deployment' => '', 'label' => 'GPT-5.6 Terra' ),
				array( 'model' => 'gpt-4.1-mini', 'deployment' => '', 'label' => 'GPT-4.1 mini' ),
				array( 'model' => 'gpt-4o', 'deployment' => '', 'label' => 'GPT-4o' ),
				array( 'model' => 'gpt-4.1', 'deployment' => '', 'label' => 'GPT-4.1' ),
				array( 'model' => 'o4-mini', 'deployment' => '', 'label' => 'o4-mini (reasoning)' ),
			),
			'azure'     => array(
				array( 'model' => 'gpt-5.6-luna', 'deployment' => 'gpt-5.6-luna', 'label' => 'GPT-5.6 Luna deployment' ),
				array( 'model' => 'gpt-5.6-terra', 'deployment' => 'gpt-5.6-terra', 'label' => 'GPT-5.6 Terra deployment' ),
				array( 'model' => 'gpt-4.1-mini', 'deployment' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini deployment' ),
			),
			'anthropic' => array(
				array( 'model' => 'claude-3-5-haiku-latest', 'deployment' => '', 'label' => 'Claude 3.5 Haiku' ),
				array( 'model' => 'claude-3-7-sonnet-latest', 'deployment' => '', 'label' => 'Claude 3.7 Sonnet' ),
				array( 'model' => 'claude-sonnet-4-0', 'deployment' => '', 'label' => 'Claude Sonnet 4' ),
			),
			'custom'    => array(
				array( 'model' => 'llama-3.3-70b-versatile', 'deployment' => '', 'label' => 'Llama 3.3 70B (Groq)' ),
				array( 'model' => 'mistral-large-latest', 'deployment' => '', 'label' => 'Mistral Large' ),
				array( 'model' => 'llama3.1', 'deployment' => '', 'label' => 'llama3.1 (Ollama)' ),
			),
		);
	}

	/**
	 * Effective model list for a provider (defaults merged with the admin mapping).
	 */
	public static function models( $provider ) {
		$defaults = self::default_models();
		$map      = (array) PCT_Settings::get( 'ai_model_map', array() );
		$list     = isset( $defaults[ $provider ] ) ? $defaults[ $provider ] : array();
		foreach ( (array) ( $map[ $provider ] ?? array() ) as $row ) {
			if ( empty( $row['model'] ) ) {
				continue;
			}
			$found = false;
			foreach ( $list as &$existing ) {
				if ( $existing['model'] === $row['model'] ) {
					$existing = array_merge( $existing, $row );
					$found    = true;
				}
			}
			unset( $existing );
			if ( ! $found ) {
				$list[] = array(
					'model'      => (string) $row['model'],
					'deployment' => (string) ( $row['deployment'] ?? '' ),
					'label'      => (string) ( $row['label'] ?? $row['model'] ),
				);
			}
		}
		return $list;
	}

	/**
	 * Deployment id for the current model (Azure / custom gateways).
	 */
	public static function deployment( $provider, $model ) {
		$explicit = (string) PCT_Settings::get( 'ai_deployment', '' );
		if ( '' !== $explicit ) {
			return $explicit;
		}
		foreach ( self::models( $provider ) as $row ) {
			if ( $row['model'] === $model && ! empty( $row['deployment'] ) ) {
				return $row['deployment'];
			}
		}
		return '';
	}

	public static function sandbox() {
		return (bool) PCT_Settings::get( 'ai_sandbox', 0 );
	}

	/**
	 * AI is usable when a provider + key are configured, or sandbox mode is on.
	 */
	public static function enabled() {
		if ( self::sandbox() ) {
			return true;
		}
		$provider = PCT_Settings::get( 'ai_provider' );
		return 'none' !== $provider && '' !== PCT_Settings::ai_key();
	}

	/**
	 * Explicit admin consent to send city database content to the AI.
	 */
	public static function city_db_consent() {
		return (bool) PCT_Settings::get( 'ai_consent_city_db', 0 );
	}

	/* ------------------------------------------------------------------ */
	/* Rate limiting                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Sliding-hour limit for all AI calls (settings: ai_rate_limit, 0 = unlimited)
	 * plus a per-visitor limit (ai_rate_limit_ip).
	 */
	public static function check_rate_limit( $scope = 'global' ) {
		$limit = (int) PCT_Settings::get( 'global' === $scope ? 'ai_rate_limit' : 'ai_rate_limit_ip', 0 );
		if ( $limit <= 0 ) {
			return true;
		}
		$key   = self::RATE_TRANSIENT . $scope . ( 'global' === $scope ? '' : '_' . md5( ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) . wp_salt() ) );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error( 'pct_ai_rate_limited', __( 'Too many AI requests right now — the built-in planner was used instead. Please try again later.', 'personalized-city-tour' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Main entry point                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Ask the model for a JSON object.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User prompt.
	 * @param array  $opts   kind, schema (array), title, retry (bool), mock (array|callable), skip_rate_limit.
	 * @return array|WP_Error Decoded and (when a schema is given) validated JSON.
	 */
	public static function complete_json( $system, $user, array $opts = array() ) {
		$opts     = wp_parse_args(
			$opts,
			array(
				'kind'            => 'tour',
				'schema'          => null,
				'title'           => '',
				'retry'           => (bool) PCT_Settings::get( 'ai_retry_invalid', 1 ),
				'mock'            => null,
				'skip_rate_limit' => false,
				'web_search'      => false,
			)
		);
		$provider = self::sandbox() ? 'sandbox' : (string) PCT_Settings::get( 'ai_provider' );
		$model    = (string) PCT_Settings::get( 'ai_model' );

		if ( ! $opts['skip_rate_limit'] ) {
			foreach ( array( 'global', 'ip' ) as $scope ) {
				$ok = self::check_rate_limit( $scope );
				if ( is_wp_error( $ok ) ) {
					PCT_AI_Log::log(
						array(
							'kind'     => $opts['kind'],
							'provider' => $provider,
							'model'    => $model,
							'status'   => 'error',
							'title'    => $opts['title'],
							'error'    => $ok->get_error_message(),
						)
					);
					return $ok;
				}
			}
		}

		$attempts = $opts['retry'] ? 2 : 1;
		$last     = null;
		for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
			$start = microtime( true );
			$raw   = self::request( $provider, $model, $system, $user, $opts );
			$ms    = (int) round( ( microtime( true ) - $start ) * 1000 );

			if ( is_wp_error( $raw ) ) {
				PCT_AI_Log::log(
					array(
						'kind'        => $opts['kind'],
						'provider'    => $provider,
						'model'       => $model,
						'status'      => 'error',
						'duration_ms' => $ms,
						'attempt'     => $attempt,
						'title'       => $opts['title'],
						'request'     => array( 'system' => $system, 'user' => $user ),
						'error'       => $raw->get_error_message(),
					)
				);
				return $raw;
			}

			$json   = self::extract_json( $raw );
			$errors = array();
			if ( null === $json ) {
				$errors[] = 'Response is not a JSON object.';
			} elseif ( is_array( $opts['schema'] ) ) {
				$errors = PCT_Schema::validate( $json, $opts['schema'] );
			}

			if ( empty( $errors ) ) {
				PCT_AI_Log::log(
					array(
						'kind'        => $opts['kind'],
						'provider'    => $provider,
						'model'       => $model,
						'status'      => 'ok',
						'duration_ms' => $ms,
						'attempt'     => $attempt,
						'title'       => $opts['title'],
						'request'     => array( 'system' => $system, 'user' => $user ),
						'response'    => $json,
					)
				);
				return $json;
			}

			$last = new WP_Error( 'pct_ai_invalid', __( 'The AI response did not match the required JSON schema.', 'personalized-city-tour' ), array( 'errors' => $errors ) );
			PCT_AI_Log::log(
				array(
					'kind'        => $opts['kind'],
					'provider'    => $provider,
					'model'       => $model,
					'status'      => 'invalid',
					'duration_ms' => $ms,
					'attempt'     => $attempt,
					'title'       => $opts['title'],
					'request'     => array( 'system' => $system, 'user' => $user ),
					'response'    => $raw,
					'error'       => implode( "\n", array_slice( $errors, 0, 20 ) ),
				)
			);

			// Adjusted prompt for the retry: quote the validation errors back to the model.
			$user = $user . "\n\nYOUR PREVIOUS ANSWER WAS REJECTED. Fix these schema violations and answer again with ONE valid JSON object only (no prose, no markdown):\n- "
				. implode( "\n- ", array_slice( $errors, 0, 12 ) );
		}
		return $last;
	}

	/**
	 * Perform a single provider request and return the raw text answer.
	 *
	 * @return string|WP_Error
	 */
	private static function request( $provider, $model, $system, $user, array $opts ) {
		if ( 'sandbox' === $provider ) {
			return self::mock_response( $opts, $system, $user );
		}
		$key = PCT_Settings::ai_key();
		if ( 'none' === $provider || '' === $key ) {
			return new WP_Error( 'pct_ai_disabled', __( 'AI provider is not configured.', 'personalized-city-tour' ) );
		}
		$temperature = floatval( PCT_Settings::get( 'ai_temperature' ) );
		$endpoint    = (string) PCT_Settings::get( 'ai_endpoint' );
		$deployment  = self::deployment( $provider, $model );

		if ( 'openai' === $provider && ! empty( $opts['web_search'] ) ) {
			return self::openai_responses_request( $key, $endpoint, $model, $system, $user, $temperature, $opts );
		}

		if ( 'devin' === $provider ) {
			return self::devin_request( $key, $endpoint, $model, $system, $user );
		}

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
				// Supports current Azure AI Foundry v1 OpenAI-compatible endpoints
				// and the older deployment-style Azure OpenAI URL for migration.
				if ( ! $endpoint ) {
					return new WP_Error( 'pct_ai_config', __( 'Azure OpenAI requires the resource endpoint URL.', 'personalized-city-tour' ) );
				}
				$url = untrailingslashit( $endpoint );
				if ( false !== strpos( $url, '/openai/v1' ) ) {
					if ( false === strpos( $url, '/chat/completions' ) ) { $url .= '/chat/completions'; }
					$headers = array( 'Content-Type' => 'application/json', 'api-key' => $key );
					$body = self::openai_body( $deployment ? $deployment : $model, $system, $user, $temperature, $opts );
					break;
				}
				if ( false === strpos( $url, '/deployments/' ) ) { $url .= '/openai/deployments/' . rawurlencode( $deployment ? $deployment : $model ); }
				if ( false === strpos( $url, '/chat/completions' ) ) { $url .= '/chat/completions'; }
				if ( false === strpos( $url, 'api-version=' ) ) { $url = add_query_arg( 'api-version', '2024-08-01-preview', $url ); }
				$headers = array( 'Content-Type' => 'application/json', 'api-key' => $key );
				$body = self::openai_body( $deployment ? $deployment : $model, $system, $user, $temperature, $opts );
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
				$body    = self::openai_body( $deployment ? $deployment : $model, $system, $user, $temperature, $opts );
				break;

			case 'openai':
			default:
				$url     = $endpoint ? $endpoint : 'https://api.openai.com/v1/chat/completions';
				$headers = array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				);
				$body    = self::openai_body( $model ? $model : 'gpt-5.6-luna', $system, $user, $temperature, $opts );
				break;
		}

		$body = apply_filters( 'pct_ai_request_body', $body, $provider );

		$response = self::post_with_retry(
			$url,
			array(
				'timeout' => (int) PCT_Settings::get( 'ai_timeout', 90 ),
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$request_id = (string) wp_remote_retrieve_header( $response, 'x-request-id' );
		$retry_after = (string) wp_remote_retrieve_header( $response, 'retry-after' );
		$remaining = (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining-tokens' );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : wp_remote_retrieve_response_message( $response );
			$meta = array();
			if ( $request_id ) { $meta[] = 'request_id=' . sanitize_text_field( $request_id ); }
			if ( $retry_after ) { $meta[] = 'retry_after=' . sanitize_text_field( $retry_after ); }
			if ( $remaining ) { $meta[] = 'remaining_tokens=' . sanitize_text_field( $remaining ); }
			if ( $meta ) { $msg .= ' [' . implode( ', ', $meta ) . ']'; }
			if ( 401 === (int) $code || 403 === (int) $code ) {
				$msg .= ' — ' . __( 'check the API key.', 'personalized-city-tour' );
			}
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
		return (string) $text;
	}

	/**
	 * POST with one automatic retry on network errors, 408/429 and 5xx.
	 * Used by every provider so a single hiccup does not fall back to the
	 * rule-based planner.
	 */
	public static function post_with_retry( $url, array $args ) {
		$response = wp_remote_post( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( is_wp_error( $response ) || 408 === $code || 429 === $code || $code >= 500 ) {
			$retry_after = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_header( $response, 'retry-after' );
			usleep( max( 1, min( 5, $retry_after ) ) * 1000000 );
			$response = wp_remote_post( $url, $args );
		}
		return $response;
	}

	/**
	 * Connection diagnostics for the settings page (never returns the key).
	 *
	 * @return array List of { label, status: ok|warn|error|skip, detail }.
	 */
	public static function diagnose() {
		$checks = array();
		$check  = function ( $label, $status, $detail = '' ) use ( &$checks ) {
			$checks[] = array( 'label' => $label, 'status' => $status, 'detail' => (string) $detail );
		};
		$provider  = (string) PCT_Settings::get( 'ai_provider', 'none' );
		$model     = (string) PCT_Settings::get( 'ai_model' );
		$endpoint  = (string) PCT_Settings::get( 'ai_endpoint' );
		$providers = self::providers();
		$check( __( 'Provider', 'personalized-city-tour' ), 'none' === $provider ? 'warn' : 'ok', isset( $providers[ $provider ] ) ? $providers[ $provider ] : $provider );
		$check( __( 'Model / deployment', 'personalized-city-tour' ), $model ? 'ok' : ( 'none' === $provider ? 'skip' : 'warn' ), $model . ( self::deployment( $provider, $model ) ? ' → ' . self::deployment( $provider, $model ) : '' ) );
		$key_src = defined( 'PCT_AI_API_KEY' ) && PCT_AI_API_KEY ? __( 'wp-config.php constant', 'personalized-city-tour' ) : ( PCT_Settings::has_secret( 'ai_api_key' ) ? __( 'encrypted in settings', 'personalized-city-tour' ) : '' );
		$check( __( 'API key', 'personalized-city-tour' ), '' !== PCT_Settings::ai_key() ? 'ok' : ( 'none' === $provider ? 'skip' : 'error' ), $key_src ? $key_src : __( 'Missing — save an API key or define PCT_AI_API_KEY in wp-config.php.', 'personalized-city-tour' ) );
		if ( in_array( $provider, array( 'azure', 'custom' ), true ) ) {
			$check( __( 'Endpoint URL', 'personalized-city-tour' ), $endpoint ? 'ok' : 'error', $endpoint ? $endpoint : __( 'Required for this provider.', 'personalized-city-tour' ) );
		} elseif ( $endpoint ) {
			$check( __( 'Endpoint URL', 'personalized-city-tour' ), 'ok', $endpoint );
		}
		$check( __( 'Sandbox mode', 'personalized-city-tour' ), self::sandbox() ? 'warn' : 'ok', self::sandbox() ? __( 'Mock responses; no provider calls are made.', 'personalized-city-tour' ) : __( 'Off', 'personalized-city-tour' ) );
		$check(
			__( 'City database consent', 'personalized-city-tour' ),
			self::city_db_consent() ? 'ok' : 'warn',
			self::city_db_consent()
				? sprintf( /* translators: %d: number of fields */ __( '%d fields allowed', 'personalized-city-tour' ), count( (array) PCT_Settings::get( 'ai_allowed_fields', array() ) ) )
				: __( 'The AI only receives the traveller profile; enable to send scoped city data.', 'personalized-city-tour' )
		);
		if ( self::city_db_consent() && ( PCT_Settings::get( 'ai_attach_drive_links' ) || PCT_Settings::get( 'ai_attach_upload_links' ) ) ) {
			$links = PCT_Sync::ai_links();
			$check( __( 'Export access links', 'personalized-city-tour' ), $links ? 'ok' : 'warn', $links ? implode( ', ', array_map( function ( $l ) { return $l['source'] . '/' . $l['type']; }, $links ) ) : __( 'No export is available yet — run "Refresh now" in the Google Drive tab.', 'personalized-city-tour' ) );
		}
		$cache = (int) PCT_Settings::get( 'ai_cache_ttl', 0 );
		$check( __( 'Cache & batching', 'personalized-city-tour' ), 'ok', sprintf( /* translators: 1: minutes, 2: seconds */ __( 'cache %1$d min · batch window %2$d s', 'personalized-city-tour' ), $cache, (int) PCT_Settings::get( 'ai_batch_window', 0 ) ) );

		if ( ! self::enabled() ) {
			$check( __( 'Live request', 'personalized-city-tour' ), 'skip', __( 'Provider not configured; the built-in planner is used.', 'personalized-city-tour' ) );
			return $checks;
		}
		$started = microtime( true );
		$result  = self::test();
		$ms      = (int) round( ( microtime( true ) - $started ) * 1000 );
		if ( is_wp_error( $result ) ) {
			$check( __( 'Live request', 'personalized-city-tour' ), 'error', $result->get_error_message() );
		} else {
			$check( __( 'Live request', 'personalized-city-tour' ), 'ok', self::sandbox() ? __( 'Sandbox answered.', 'personalized-city-tour' ) : sprintf( /* translators: %d: milliseconds */ __( 'Valid JSON received in %d ms.', 'personalized-city-tour' ), $ms ) );
		}
		return $checks;
	}

	/**
	 * Devin.ai integration through the sessions API: a session is created with
	 * the prompt and polled until it reports structured output. Endpoint and
	 * polling budget are configurable (ai_endpoint, ai_timeout).
	 */
	private static function devin_request( $key, $endpoint, $model, $system, $user ) {
		$base    = untrailingslashit( $endpoint ? $endpoint : 'https://api.devin.ai/v1' );
		$headers = array(
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $key,
		);
		$prompt  = $system . "\n\n" . $user . "\n\nReturn the final JSON object as the session's structured output and do not perform any other actions.";
		$body    = apply_filters(
			'pct_ai_request_body',
			array(
				'prompt'     => $prompt,
				'idempotent' => false,
				'title'      => 'Personalized City Tour request',
				'tags'       => array( 'personalized-city-tour' ),
			),
			'devin'
		);
		if ( $model && 'devin' !== $model ) {
			$body['model'] = $model;
		}
		$response = wp_remote_post(
			$base . '/sessions',
			array(
				'timeout' => 60,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || empty( $data['session_id'] ) ) {
			$msg = isset( $data['detail'] ) ? ( is_string( $data['detail'] ) ? $data['detail'] : wp_json_encode( $data['detail'] ) ) : wp_remote_retrieve_response_message( $response );
			/* translators: 1: HTTP status, 2: provider error message */
			return new WP_Error( 'pct_ai_http', sprintf( __( 'Devin session could not be created (%1$s): %2$s', 'personalized-city-tour' ), $code, $msg ) );
		}
		$session  = (string) $data['session_id'];
		$deadline = time() + max( 30, (int) PCT_Settings::get( 'ai_timeout', 90 ) );
		while ( time() < $deadline ) {
			sleep( 5 );
			$poll = wp_remote_get( $base . '/session/' . rawurlencode( $session ), array( 'timeout' => 30, 'headers' => $headers ) );
			if ( is_wp_error( $poll ) ) {
				return $poll;
			}
			$state = json_decode( wp_remote_retrieve_body( $poll ), true );
			if ( ! empty( $state['structured_output'] ) ) {
				return is_string( $state['structured_output'] ) ? $state['structured_output'] : wp_json_encode( $state['structured_output'] );
			}
			$status = (string) ( $state['status_enum'] ?? $state['status'] ?? '' );
			if ( in_array( $status, array( 'finished', 'blocked', 'expired', 'stopped' ), true ) ) {
				// Look for a JSON object in the last Devin message.
				$messages = (array) ( $state['messages'] ?? array() );
				for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
					$m = $messages[ $i ];
					if ( isset( $m['message'] ) && ( ! isset( $m['type'] ) || 'devin_message' === $m['type'] ) && null !== self::extract_json( $m['message'] ) ) {
						return (string) $m['message'];
					}
				}
				return new WP_Error( 'pct_ai_devin_state', sprintf( /* translators: %s: status */ __( 'Devin session ended (%s) without structured output.', 'personalized-city-tour' ), $status ) );
			}
		}
		return new WP_Error( 'pct_ai_timeout', __( 'Devin did not answer within the configured time budget.', 'personalized-city-tour' ) );
	}


	/**
	 * OpenAI Responses API adapter with the hosted web_search tool. This is
	 * intentionally limited to the first-party OpenAI provider; Azure/custom
	 * gateways continue to use their existing Chat Completions contract.
	 */
	private static function openai_responses_request( $key, $endpoint, $model, $system, $user, $temperature, array $opts = array() ) {
		$url = 'https://api.openai.com/v1/responses';
		// A custom endpoint is only used when it already points at the Responses API.
		if ( $endpoint && false !== strpos( $endpoint, '/responses' ) ) {
			$url = $endpoint;
		}
		$body = array(
			'model'        => $model ? $model : 'gpt-5.6-luna',
			'instructions' => $system,
			'input'        => $user,
			'tools'        => array(
				array(
					'type'                => 'web_search',
					'search_context_size' => 'medium',
				),
			),
			'store' => false,
		);
		if ( preg_match( '/^gpt-5/', strtolower( $model ) ) ) {
			$body['reasoning'] = array( 'effort' => 'low' );
		} elseif ( $temperature >= 0 ) {
			$body['temperature'] = $temperature;
		}
		if ( ! empty( $opts['schema'] ) && is_array( $opts['schema'] ) ) {
			$body['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'pct_tour_response',
					'strict' => true,
					'schema' => self::strict_schema( $opts['schema'] ),
				),
			);
		} else {
			$body['text'] = array( 'format' => array( 'type' => 'json_object' ) );
		}
		$body = apply_filters( 'pct_ai_responses_request_body', $body, 'openai' );
		$response = self::post_with_retry(
			$url,
			array(
				'timeout' => (int) PCT_Settings::get( 'ai_timeout', 90 ),
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				),
				'body' => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$request_id = (string) wp_remote_retrieve_header( $response, 'x-request-id' );
		$retry_after = (string) wp_remote_retrieve_header( $response, 'retry-after' );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) ? (string) ( $data['error']['message'] ?? $data['message'] ?? '' ) : '';
			if ( '' === $msg ) { $msg = wp_remote_retrieve_response_message( $response ); }
			$meta = array();
			if ( $request_id ) { $meta[] = 'request_id=' . sanitize_text_field( $request_id ); }
			if ( $retry_after ) { $meta[] = 'retry_after=' . sanitize_text_field( $retry_after ); }
			if ( $meta ) { $msg .= ' [' . implode( ', ', $meta ) . ']'; }
			return new WP_Error( 'pct_ai_http', sprintf( __( 'AI web-search request failed (%1$s): %2$s', 'personalized-city-tour' ), $code, $msg ) );
		}
		if ( isset( $data['output_text'] ) && is_string( $data['output_text'] ) ) {
			return $data['output_text'];
		}
		$text = '';
		foreach ( (array) ( $data['output'] ?? array() ) as $item ) {
			if ( 'message' !== ( $item['type'] ?? '' ) ) { continue; }
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
				if ( 'output_text' === ( $content['type'] ?? '' ) && isset( $content['text'] ) ) {
					$text .= (string) $content['text'];
				}
			}
		}
		return trim( $text );
	}

	private static function openai_body( $model, $system, $user, $temperature, array $opts = array() ) {
		$body = array(
			'model'    => $model,
			'messages' => array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			),
		);
		// GPT-5.6 reasoning models use reasoning effort instead of sampling
		// temperature; omitting unsupported fields keeps this adapter portable.
		if ( ! preg_match( '/^(gpt-5(?:\.6)?|o[0-9])/', strtolower( $model ) ) ) {
			$body['temperature'] = $temperature;
		}
		if ( preg_match( '/^gpt-5\.6-/', strtolower( $model ) ) ) {
			$body['reasoning_effort'] = 'low';
			$body['max_completion_tokens'] = 5000;
		}
		if ( ! empty( $opts['schema'] ) && is_array( $opts['schema'] ) ) {
			$body['response_format'] = array(
				'type' => 'json_schema',
				'json_schema' => array(
					'name' => 'pct_tour_response',
					'strict' => true,
					'schema' => self::strict_schema( $opts['schema'] ),
				),
			);
		} else {
			$body['response_format'] = array( 'type' => 'json_object' );
		}
		return $body;
	}

	private static function strict_schema( $schema ) {
		if ( ! is_array( $schema ) ) { return $schema; }
		$out = array();
		foreach ( $schema as $key => $value ) {
			if ( in_array( $key, array( 'additionalProperties', 'required' ), true ) ) { continue; }
			if ( 'properties' === $key && is_array( $value ) ) {
				$out['properties'] = array();
				foreach ( $value as $name => $property ) { $out['properties'][ $name ] = self::strict_schema( $property ); }
				$out['required'] = array_keys( $value );
				continue;
			}
			if ( 'items' === $key && is_array( $value ) ) { $out[ $key ] = self::strict_schema( $value ); continue; }
			$out[ $key ] = $value;
		}
		if ( isset( $out['type'] ) && 'object' === $out['type'] ) {
			$out['additionalProperties'] = false;
			if ( ! isset( $out['required'] ) ) { $out['required'] = array(); }
		}
		return $out;
	}

	/**
	 * Sandbox answer: callers pass a mock array/callable; otherwise a generic OK.
	 */
	private static function mock_response( array $opts, $system, $user ) {
		$mock = $opts['mock'];
		if ( is_callable( $mock ) ) {
			$mock = call_user_func( $mock, $system, $user );
		}
		if ( is_wp_error( $mock ) ) {
			return $mock;
		}
		if ( null === $mock ) {
			$mock = array( 'ok' => true, 'message' => 'sandbox' );
		}
		$mock = apply_filters( 'pct_ai_mock_response', $mock, $opts, $system, $user );
		return is_string( $mock ) ? $mock : wp_json_encode( $mock, JSON_UNESCAPED_UNICODE );
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
			'Return {"ok": true, "message": "hello"}',
			array(
				'kind'            => 'test',
				'title'           => 'Connectivity test',
				'retry'           => false,
				'skip_rate_limit' => true,
				'schema'          => array(
					'type'       => 'object',
					'required'   => array( 'ok' ),
					'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return ! empty( $result['ok'] ) ? true : new WP_Error( 'pct_ai_test', __( 'Unexpected response from provider.', 'personalized-city-tour' ) );
	}

	/**
	 * Structured title for a tour request, e.g.
	 * "Requested PCT from Jane Doe in language fa for city Isfahan in date 1404/06/30 - 14:05".
	 * The full name is replaced by "Traveller" unless the admin allowed personal data in prompts.
	 */
	public static function request_title( $name, $lang, $city, $timestamp = 0 ) {
		$timestamp = $timestamp ? $timestamp : current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$name      = PCT_Settings::get( 'ai_share_name', 0 ) && $name ? $name : __( 'Traveller', 'personalized-city-tour' );
		$date      = PCT_I18n::format_date( $timestamp, $lang );
		return sprintf( 'Requested PCT from %1$s in language %2$s for city %3$s in date %4$s - %5$s', $name, $lang, $city, $date, gmdate( 'H:i', $timestamp ) );
	}
}
