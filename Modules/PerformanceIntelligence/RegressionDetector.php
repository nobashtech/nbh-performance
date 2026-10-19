<?php
/**
 * Laboratory performance regression detection and alerts.
 *
 * @package NBHPerformance
 */

namespace NBH\Performance\Modules\PerformanceIntelligence;

final class RegressionDetector {
	const OPTION = 'nbh_performance_performance_regression_alerts';

	/** @var LabTestStorage */
	private $storage;

	public function __construct( LabTestStorage $storage ) {
		$this->storage = $storage;
	}

	/** Compare a result against its baseline or previous successful run. */
	public function evaluate( $result_id ) {
		$current = $this->storage->get( $result_id );
		if ( empty( $current ) || 'complete' !== $current['status'] ) {
			return array();
		}
		$before = $this->storage->comparison_for( $current );
		if ( empty( $before ) ) {
			return array();
		}
		$issues = array();
		$this->lower_is_better_percent( $issues, 'performance_score', $before, $current, max( 3, absint( get_option( 'nbh_performance_regression_score_drop', 5 ) ) ), true );
		$this->higher_is_worse_percent( $issues, 'lcp', $before, $current, max( 5, absint( get_option( 'nbh_performance_regression_lcp_percent', 12 ) ) ) );
		$this->higher_is_worse_percent( $issues, 'tbt', $before, $current, max( 5, absint( get_option( 'nbh_performance_regression_tbt_percent', 20 ) ) ) );
		$this->higher_is_worse_percent( $issues, 'ttfb', $before, $current, max( 5, absint( get_option( 'nbh_performance_regression_ttfb_percent', 15 ) ) ) );
		$cls_delta = is_numeric( $before['cls'] ) && is_numeric( $current['cls'] ) ? (float) $current['cls'] - (float) $before['cls'] : 0;
		$cls_limit = max( 0.01, (float) get_option( 'nbh_performance_regression_cls_delta', 0.05 ) );
		if ( $cls_delta >= $cls_limit ) {
			$issues[] = array(
				'metric' => 'cls',
				'before' => (float) $before['cls'],
				'after'  => (float) $current['cls'],
				'change' => round( $cls_delta, 4 ),
				'unit'   => 'absolute',
			);
		}
		if ( empty( $issues ) ) {
			return array();
		}
		$severity = count( $issues ) >= 2 || $this->contains_severe_change( $issues ) ? 'critical' : 'warning';
		$alert    = array(
			'id'           => wp_generate_uuid4(),
			'severity'     => $severity,
			'url'          => $current['canonical_url'],
			'url_hash'     => $current['url_hash'],
			'strategy'     => $current['strategy'],
			'result_id'    => absint( $current['id'] ),
			'baseline_id'  => absint( $before['id'] ),
			'issues'       => $issues,
			'created_at'   => time(),
			'acknowledged' => false,
		);
		$alerts   = (array) get_option( self::OPTION, array() );
		array_unshift( $alerts, $alert );
		$alerts = array_slice( $alerts, 0, 100 );
		update_option( self::OPTION, $alerts, false );
		do_action( 'nbh_performance_performance_regression_detected', $alert, $current, $before );
		$this->maybe_email( $alert );
		return $alert;
	}

	/** Return recent alerts. */
	public function alerts( $limit = 50 ) {
		return array_slice( array_values( (array) get_option( self::OPTION, array() ) ), 0, max( 1, min( 100, absint( $limit ) ) ) );
	}

	/** Mark one alert acknowledged. */
	public function acknowledge( $id ) {
		$alerts  = (array) get_option( self::OPTION, array() );
		$changed = false;
		foreach ( $alerts as &$alert ) {
			if ( isset( $alert['id'] ) && hash_equals( (string) $alert['id'], (string) $id ) ) {
				$alert['acknowledged']    = true;
				$alert['acknowledged_at'] = time();
				$changed                  = true;
			}
		}
		if ( $changed ) {
			update_option( self::OPTION, $alerts, false );
		}
		return $changed;
	}

	private function lower_is_better_percent( array &$issues, $metric, array $before, array $after, $limit, $score = false ) {
		if ( ! is_numeric( $before[ $metric ] ) || ! is_numeric( $after[ $metric ] ) ) {
			return;
		}
		$old       = (float) $before[ $metric ];
		$new_value = (float) $after[ $metric ];
		$change    = $score ? $old - $new_value : ( $old > 0 ? ( ( $old - $new_value ) / $old ) * 100 : 0 );
		if ( $change >= $limit ) {
			$issues[] = array(
				'metric' => $metric,
				'before' => $old,
				'after'  => $new_value,
				'change' => round( $change, 2 ),
				'unit'   => $score ? 'points' : 'percent',
			);
		}
	}

	private function higher_is_worse_percent( array &$issues, $metric, array $before, array $after, $limit ) {
		if ( ! is_numeric( $before[ $metric ] ) || ! is_numeric( $after[ $metric ] ) || (float) $before[ $metric ] <= 0 ) {
			return;
		}
		$old       = (float) $before[ $metric ];
		$new_value = (float) $after[ $metric ];
		$change    = ( ( $new_value - $old ) / $old ) * 100;
		if ( $change >= $limit ) {
			$issues[] = array(
				'metric' => $metric,
				'before' => $old,
				'after'  => $new_value,
				'change' => round( $change, 2 ),
				'unit'   => 'percent',
			);
		}
	}

	private function contains_severe_change( array $issues ) {
		foreach ( $issues as $issue ) {
			if ( 'performance_score' === $issue['metric'] && $issue['change'] >= 15 ) {
				return true;
			}
			if ( 'lcp' === $issue['metric'] && $issue['change'] >= 30 ) {
				return true;
			}
		}
		return false;
	}

	private function maybe_email( array $alert ) {
		if ( ! get_option( 'nbh_performance_regression_email_alerts', 0 ) ) {
			return;
		}
		$recipient = sanitize_email( get_option( 'admin_email' ) );
		if ( ! is_email( $recipient ) ) {
			return;
		}
		$subject = sprintf( '[NBH Performance] %s performance regression', ucfirst( $alert['severity'] ) );
		$body    = "NBH Performance detected a performance regression.\n\nURL: {$alert['url']}\nDevice: {$alert['strategy']}\nMetrics: " . implode( ', ', wp_list_pluck( $alert['issues'], 'metric' ) ) . "\n\nReview Performance Insights in WordPress admin.";
		wp_mail( $recipient, $subject, $body );
	}
}
