<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AWI_Rate_Limiter {

	const LIMIT            = 20;    // imports allowed per window
	const WINDOW_SECONDS   = 3600;  // 1 hour
	const COOLDOWN_SECONDS = 28800; // 8 hours
	const META_KEY         = '_awi_rate_limit';

	/**
	 * Returns true when the site has an active Pro/paid Freemius licence.
	 * Safe to call even when the Freemius SDK is not installed.
	 */
	public static function is_pro(): bool {
		return function_exists( 'atw_fs_is_pro' ) && atw_fs_is_pro();
	}

	/**
	 * Check whether a user may import right now.
	 * Does NOT record the import — call record() after confirmed success.
	 *
	 * @return array{allowed:bool, reason:string, remaining:int, cooldown_until:int, cooldown_seconds:int, window_reset_at:int, is_pro:bool}
	 */
	public static function check( int $user_id ): array {
		// Pro plan: unlimited imports, no cooldown.
		if ( self::is_pro() ) {
			return array(
				'allowed'          => true,
				'reason'           => 'pro',
				'remaining'        => -1,    // -1 signals "unlimited" to the UI
				'cooldown_until'   => 0,
				'cooldown_seconds' => 0,
				'window_reset_at'  => 0,
				'is_pro'           => true,
			);
		}

		$state = self::get_state( $user_id );
		$now   = time();

		// Active cooldown still running.
		if ( $state['cooldown_until'] > 0 && $now < $state['cooldown_until'] ) {
			return array(
				'allowed'          => false,
				'reason'           => 'cooldown',
				'remaining'        => 0,
				'cooldown_until'   => $state['cooldown_until'],
				'cooldown_seconds' => $state['cooldown_until'] - $now,
				'window_reset_at'  => $state['cooldown_until'],
				'is_pro'           => false,
			);
		}

		// Window expired (or never started) — treat as a fresh window.
		if ( $state['window_start'] === 0 || ( $now - $state['window_start'] ) >= self::WINDOW_SECONDS ) {
			return array(
				'allowed'          => true,
				'reason'           => '',
				'remaining'        => self::LIMIT,
				'cooldown_until'   => 0,
				'cooldown_seconds' => 0,
				'window_reset_at'  => $now + self::WINDOW_SECONDS,
				'is_pro'           => false,
			);
		}

		$remaining = self::LIMIT - $state['count'];

		// Limit already exhausted — start cooldown if not yet set.
		if ( $remaining <= 0 ) {
			if ( $state['cooldown_until'] === 0 ) {
				$state['cooldown_until'] = $now + self::COOLDOWN_SECONDS;
				self::save_state( $user_id, $state );
			}
			return array(
				'allowed'          => false,
				'reason'           => 'limit_reached',
				'remaining'        => 0,
				'cooldown_until'   => $state['cooldown_until'],
				'cooldown_seconds' => max( 0, $state['cooldown_until'] - $now ),
				'window_reset_at'  => $state['cooldown_until'],
				'is_pro'           => false,
			);
		}

		return array(
			'allowed'          => true,
			'reason'           => '',
			'remaining'        => $remaining,
			'cooldown_until'   => 0,
			'cooldown_seconds' => 0,
			'window_reset_at'  => $state['window_start'] + self::WINDOW_SECONDS,
			'is_pro'           => false,
		);
	}

	/**
	 * Record one successful import and return updated quota status.
	 */
	public static function record( int $user_id ): array {
		$state = self::get_state( $user_id );
		$now   = time();

		// Reset if window expired or cooldown is over.
		$window_expired   = ( $state['window_start'] === 0 || ( $now - $state['window_start'] ) >= self::WINDOW_SECONDS );
		$cooldown_expired = ( $state['cooldown_until'] > 0 && $now >= $state['cooldown_until'] );

		if ( $window_expired || $cooldown_expired ) {
			$state = array(
				'window_start'   => $now,
				'count'          => 0,
				'cooldown_until' => 0,
			);
		}

		$state['count']++;

		// Trigger cooldown when limit is reached.
		if ( $state['count'] >= self::LIMIT && $state['cooldown_until'] === 0 ) {
			$state['cooldown_until'] = $now + self::COOLDOWN_SECONDS;
		}

		self::save_state( $user_id, $state );

		return self::check( $user_id );
	}

	/**
	 * Read-only quota status for UI display.
	 */
	public static function get_status( int $user_id ): array {
		return self::check( $user_id );
	}

	/**
	 * Format quota status as a human-readable summary string.
	 */
	public static function format_status( array $status ): string {
		if ( ! $status['allowed'] ) {
			$mins = (int) ceil( $status['cooldown_seconds'] / 60 );
			$hrs  = floor( $mins / 60 );
			$rem  = $mins % 60;
			if ( $hrs > 0 ) {
				return sprintf( 'Limit reached. Cooldown: %dh %dm remaining.', $hrs, $rem );
			}
			return sprintf( 'Limit reached. Cooldown: %d min remaining.', $mins );
		}

		$reset_in = max( 0, $status['window_reset_at'] - time() );
		$mins     = (int) ceil( $reset_in / 60 );
		return sprintf( '%d of %d imports remaining (window resets in %d min).', $status['remaining'], self::LIMIT, $mins );
	}

	/**
	 * Hard-reset a user's rate limit state (admin use, e.g. uninstall).
	 */
	public static function reset( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
	}

	// ── Internal ─────────────────────────────────────────────────────────────

	private static function get_state( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $raw ) ) {
			return array(
				'window_start'   => 0,
				'count'          => 0,
				'cooldown_until' => 0,
			);
		}
		return array(
			'window_start'   => isset( $raw['window_start'] )   ? (int) $raw['window_start']   : 0,
			'count'          => isset( $raw['count'] )          ? (int) $raw['count']          : 0,
			'cooldown_until' => isset( $raw['cooldown_until'] ) ? (int) $raw['cooldown_until'] : 0,
		);
	}

	private static function save_state( int $user_id, array $state ): void {
		update_user_meta( $user_id, self::META_KEY, $state );
	}
}
