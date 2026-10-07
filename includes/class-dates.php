<?php
namespace JPCRM_Courses;

defined( 'ABSPATH' ) || exit;

final class Dates {
	public static function parse( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value ) ) {
			throw new \InvalidArgumentException( __( 'Enter a valid date in YYYY-MM-DD format.', 'jpcrm-courses' ) );
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value || (int) $date->format( 'Y' ) < 1900 ) {
			throw new \InvalidArgumentException( __( 'Enter a real calendar date, from 1900 onwards.', 'jpcrm-courses' ) );
		}
		return $date;
	}

	public static function rule( $amount, $unit ) {
		$limits = array( 'days' => 36500, 'months' => 1200, 'years' => 100, 'none' => 0 );
		if ( ! isset( $limits[ $unit ] ) ) {
			throw new \InvalidArgumentException( __( 'Choose a valid expiry unit.', 'jpcrm-courses' ) );
		}
		if ( 'none' === $unit ) {
			return array( 0, 'none' );
		}
		if ( false === filter_var( $amount, FILTER_VALIDATE_INT ) || $amount < 1 || $amount > $limits[ $unit ] ) {
			throw new \InvalidArgumentException( __( 'Expiry must be a positive whole number, no more than 100 years.', 'jpcrm-courses' ) );
		}
		return array( (int) $amount, $unit );
	}

	/** Calendar arithmetic: 29 February + one year = 28 February. */
	public static function expiry( $value, $amount, $unit ) {
		$date = self::parse( $value );
		list( $amount, $unit ) = self::rule( $amount, $unit );
		if ( 'none' === $unit ) {
			return null;
		}
		if ( 'days' === $unit ) {
			$expiry = $date->modify( '+' . $amount . ' days' );
		} else {
			$months = 'years' === $unit ? 12 * $amount : $amount;
			$first = $date->modify( 'first day of this month' )->modify( '+' . $months . ' months' );
			$expiry = $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), min( (int) $date->format( 'd' ), (int) $first->format( 't' ) ) );
		}
		if ( (int) $expiry->format( 'Y' ) > 9999 ) {
			throw new \InvalidArgumentException( __( 'The calculated expiry exceeds the supported date range.', 'jpcrm-courses' ) );
		}
		return $expiry->format( 'Y-m-d' );
	}

	public static function filters( $input ) {
		$get = static function ( $key ) use ( $input ) {
			return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';
		};
		$filters = array(
			'course_type_id' => absint( $get( 'course_type_id' ) ),
			'contact_id' => absint( $get( 'contact_id' ) ),
			'expiry_month' => $get( 'expiry_month' ),
			'from' => $get( 'from' ), 'to' => $get( 'to' ),
			'latest' => '1' === $get( 'latest' ),
			'status' => $get( 'status' ),
			'search' => mb_substr( $get( 'search' ), 0, 150 ),
		);
		if ( $filters['expiry_month'] ) {
			if ( ! preg_match( '/^[0-9]{4}-[0-9]{2}$/D', $filters['expiry_month'] ) ) {
				throw new \InvalidArgumentException( __( 'Choose a valid expiry month.', 'jpcrm-courses' ) );
			}
			$date = self::parse( $filters['expiry_month'] . '-01' );
			if ( $filters['from'] || $filters['to'] ) {
				throw new \InvalidArgumentException( __( 'Use an expiry month or a date range, not both.', 'jpcrm-courses' ) );
			}
			$filters['from'] = $date->format( 'Y-m-d' );
			$filters['to'] = $date->modify( 'last day of this month' )->format( 'Y-m-d' );
		}
		foreach ( array( 'from', 'to' ) as $key ) {
			if ( $filters[ $key ] ) { self::parse( $filters[ $key ] ); }
		}
		if ( $filters['from'] && $filters['to'] && $filters['from'] > $filters['to'] ) {
			throw new \InvalidArgumentException( __( 'The start of the expiry range must be before its end.', 'jpcrm-courses' ) );
		}
		if ( ! in_array( $filters['status'], array( '', 'expired', 'current', 'soon', 'none' ), true ) ) {
			throw new \InvalidArgumentException( __( 'Choose a valid certificate status.', 'jpcrm-courses' ) );
		}
		return $filters;
	}
}
